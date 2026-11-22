<?php

namespace App\Services\Installation;

use App\Enums\SystemRole;
use App\Models\User;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseManager;
use App\Services\License\LicenseSignatureVerifier;
use App\Services\SettingsService;
use App\Support\Appearance;
use App\Support\Branding;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Performs the installation from validated wizard data.
 *
 * Order matters for safe recovery:
 *   1. mark progress "installing" for this database target
 *   2. migrate (forward only — never drops tables) and seed (idempotent)
 *   3. create/update the administrator, settings and license activation
 *   4. write .env
 *   5. write the lock file — the installation only counts as done here
 * If anything fails, the lock is not written and the wizard can be re-run
 * against the same database to resume without losing data.
 */
class InstallationService
{
    public function __construct(
        protected InstallationState $state,
        protected EnvironmentWriter $env,
        protected DatabaseConfigurator $database,
        protected LicenseActivationService $activations,
        protected LicenseSignatureVerifier $verifier,
        protected LicenseManager $licenses,
        protected SettingsService $settings,
    ) {}

    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string, prefix: ?string}  $db
     * @param  array{name: string, url: string, timezone: string, currency: string, locale: string}  $app
     * @param  array{name: string, email: string, password_hash: string}  $admin
     * @param  array{key: string, provider: string, payload: string, signature: string, messages?: list<string>, offline?: bool}  $license
     * @param  array{redis?: array<string, mixed>, mail?: array<string, mixed>, customize?: array<string, mixed>}  $options
     * @return array{completion_token: string}
     */
    public function install(array $db, array $app, array $admin, array $license, array $options = []): array
    {
        if ($this->state->isInstalled()) {
            throw new RuntimeException('LabelDesk is already installed.');
        }

        $installationId = $this->state->ensureInstallationId();
        $prefix = (string) ($db['prefix'] ?? '');

        $this->state->updateProgress([
            'status' => 'installing',
            'started_at' => now()->toIso8601String(),
            'database_target' => $this->state->databaseTarget($db['host'], $db['database'], $prefix),
            'stage' => 'migrate',
        ]);

        try {
            $connection = $this->database->apply($db);

            $this->call('migrate', ['--force' => true, '--database' => $connection]);
            $this->state->updateProgress(['stage' => 'seed']);
            $this->call('db:seed', ['--force' => true, '--class' => DatabaseSeeder::class, '--database' => $connection]);

            $this->state->updateProgress(['stage' => 'configure']);
            $user = $this->createAdministrator($admin);
            $this->storeSettings($app, $installationId);
            $this->storeMail((array) ($options['mail'] ?? []));
            $this->storeAppearance((array) ($options['customize'] ?? []));
            $this->storeLicense($app['url'], $installationId, $license, $user);

            $this->state->updateProgress(['stage' => 'environment']);
            $this->writeEnvironment($db, $app, (array) ($options['redis'] ?? []));
            $this->linkStorage();

            $token = Str::random(40);
            $this->state->writeLock([
                'installation_id' => $installationId,
                'administrator' => $user->email,
            ]);
            $this->state->updateProgress([
                'status' => 'completed',
                'stage' => 'done',
                'completed_at' => now()->toIso8601String(),
                'completion_token' => hash('sha256', $token),
                'summary' => [
                    'app_name' => $app['name'],
                    'app_url' => $app['url'],
                    'admin_email' => $user->email,
                    'plan' => $license['plan'] ?? null,
                ],
            ]);

            return ['completion_token' => $token];
        } catch (Throwable $e) {
            // Never log credentials: only the exception class and message.
            Log::error('LabelDesk installation failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            $this->state->updateProgress(['last_error' => Str::limit($e->getMessage(), 300)]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function call(string $command, array $parameters): void
    {
        $exit = Artisan::call($command, $parameters);

        if ($exit !== 0) {
            throw new RuntimeException("Installation step [{$command}] failed: ".trim(Artisan::output()));
        }
    }

    /**
     * @param  array{name: string, email: string, password_hash: string}  $admin
     */
    protected function createAdministrator(array $admin): User
    {
        return DB::transaction(function () use ($admin) {
            $user = User::withTrashed()->firstOrNew(['email' => strtolower($admin['email'])]);

            // The password is already a bcrypt hash; assign the raw attribute
            // so the "hashed" cast does not hash it a second time.
            $user->setRawAttributes(array_merge($user->getAttributes(), [
                'name' => $admin['name'],
                'email' => strtolower($admin['email']),
                'password' => $admin['password_hash'],
                'is_active' => true,
                'theme' => $user->getAttributes()['theme'] ?? null,
                'deleted_at' => null,
            ]));
            $user->email_verified_at ??= now();
            $user->save();

            $user->syncRoles([SystemRole::SuperAdmin->value]);

            return $user;
        });
    }

    /**
     * @param  array{name: string, url: string, timezone: string, currency: string, locale: string}  $app
     */
    protected function storeSettings(array $app, string $installationId): void
    {
        $this->settings->setMany([
            'general.app_name' => $app['name'],
            'general.timezone' => $app['timezone'],
            'general.currency' => $app['currency'],
            'general.locale' => $app['locale'],
            'system.installation_id' => $installationId,
            'system.installed_version' => (string) config('labeldesk.product.version'),
        ]);
    }

    /**
     * @param  array{key: string, provider: string, payload: string, signature: string, secret: ?string, message: ?string}  $license
     */
    protected function storeLicense(string $url, string $installationId, array $license, User $user): void
    {
        $context = ActivationContext::for($url, $installationId);

        // Re-verify the signed token captured at the license step; nothing
        // from the session is trusted without its signature.
        $result = $this->verifier->verify(
            $license['payload'],
            $license['signature'],
            $this->licenses->provider($license['provider'])->publicKey(),
            $context,
            ['messages' => $license['messages'] ?? []],
            allowExpiredToken: true,
        );

        $this->activations->persist($context, $license['key'], $result, $license['provider'], $user, request()?->ip(), (bool) ($license['offline'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $db
     * @param  array<string, mixed>  $app
     */
    protected function writeEnvironment(array $db, array $app, array $redis = []): void
    {
        $developer = app()->environment('local');
        $https = str_starts_with(strtolower($app['url']), 'https://');

        $this->env->set([
            'APP_NAME' => $app['name'],
            'APP_ENV' => $developer ? 'local' : 'production',
            'APP_DEBUG' => $developer,
            'APP_URL' => rtrim($app['url'], '/'),
            'APP_LOCALE' => $app['locale'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
            'DB_PREFIX' => (string) ($db['prefix'] ?? ''),
            'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => $https,
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'LABELDESK_TIMEZONE' => $app['timezone'],
            'LABELDESK_CURRENCY' => $app['currency'],
        ]);

        // Step 3: Redis for cache / sessions / queue (database drivers otherwise).
        if (! empty($redis['enabled'])) {
            $this->env->set([
                'REDIS_CLIENT' => (string) ($redis['client'] ?? 'predis'),
                'REDIS_SCHEME' => ! empty($redis['tls']) ? 'tls' : 'tcp',
                'REDIS_HOST' => (string) $redis['host'],
                'REDIS_PORT' => (string) $redis['port'],
                'REDIS_USERNAME' => (string) ($redis['username'] ?? ''),
                'REDIS_PASSWORD' => (string) ($redis['password'] ?? ''),
                'REDIS_DB' => (string) $redis['database'],
                'REDIS_CACHE_DB' => (string) $redis['database'],
                'CACHE_STORE' => ! empty($redis['use_cache']) ? 'redis' : 'database',
                'SESSION_DRIVER' => ! empty($redis['use_sessions']) ? 'redis' : 'database',
                'QUEUE_CONNECTION' => ! empty($redis['use_queue']) ? 'redis' : 'database',
            ]);
        }
    }

    /**
     * Step 4: outgoing email (Settings → Email). The password is stored encrypted.
     */
    protected function storeMail(array $mail): void
    {
        if (($mail['mailer'] ?? null) !== 'smtp') {
            $this->settings->set('mail.mailer', 'log');

            return;
        }

        $this->settings->setMany([
            'mail.mailer' => 'smtp',
            'mail.host' => (string) $mail['host'],
            'mail.port' => (int) $mail['port'],
            'mail.scheme' => ($mail['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : '',
            'mail.username' => (string) ($mail['username'] ?? ''),
            'mail.from_address' => (string) $mail['from_address'],
            'mail.from_name' => (string) ($mail['from_name'] ?? ''),
        ]);
        if (filled($mail['password'] ?? null)) {
            $this->settings->set('mail.password', (string) $mail['password'], 'encrypted');
        }
    }

    /**
     * Step 6: look and sign-in page (Settings → Appearance).
     */
    protected function storeAppearance(array $c): void
    {
        if ($c === []) {
            return;
        }

        $values = [
            'appearance.theme' => $c['theme'],
            'appearance.font' => $c['font'],
            'appearance.modes' => $c['modes'],
            'appearance.default_mode' => $c['default_mode'],
            'appearance.sidebar' => $c['sidebar'],
            'appearance.login_layout' => $c['login_layout'],
            'appearance.brand_color' => ! empty($c['custom_colors']) ? $c['brand_color'] : null,
            'appearance.brand_color_2' => ! empty($c['custom_colors']) ? $c['brand_color_2'] : null,
            'general.date_format' => $c['date_format'] ?? 'd M Y',
        ];
        $defaults = Appearance::loginTextDefaults();
        foreach (($c['login'] ?? []) as $key => $value) {
            if (array_key_exists($key, $defaults)) {
                $values['appearance.login_'.$key] = $value === '' || $value === $defaults[$key] ? null : $value;
            }
        }
        foreach (array_keys(Branding::KINDS) as $kind) {
            $name = $c['files'][$kind] ?? null;
            $values['appearance.'.$kind] = is_string($name) && preg_match(Branding::NAME_PATTERN, $name) ? $name : null;
        }

        $this->settings->setMany($values);
    }

    protected function linkStorage(): void
    {
        if (is_link(public_path('storage')) || is_dir(public_path('storage'))) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (Throwable) {
            // Some shared hosts disable symlink(); public uploads then use the
            // fallback route documented in the installation guide.
        }
    }
}

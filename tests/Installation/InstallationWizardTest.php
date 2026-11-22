<?php

namespace Tests\Installation;

use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\Installation\DatabaseConfigurator;
use App\Services\Installation\DatabaseInspector;
use App\Services\Installation\EnvironmentWriter;
use App\Services\Installation\InstallationState;
use App\Services\Installation\RedisInspector;
use App\Services\License\LicenseAuthority;
use App\Services\SettingsService;
use Dotenv\Dotenv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Walks the complete browser wizard against the in-memory SQLite test
 * database (MySQL and Redis connection tests are mocked).
 *
 * Steps: welcome → 0 license → 1 requirements → 2 database → 3 redis
 * (optional) → 4 mail (can be skipped) → 5 super admin → 6 customise → install.
 */
class InstallationWizardTest extends TestCase
{
    protected bool $installed = false;

    protected string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = storage_path('framework/testing/install-'.uniqid().'.env');
        file_put_contents($this->envPath, "APP_NAME=LabelDesk\nAPP_ENV=production\nDB_CONNECTION=mysql\n");
        $this->app->instance(EnvironmentWriter::class, new EnvironmentWriter($this->envPath));

        // Use the test connection instead of switching to MySQL.
        $this->app->instance(DatabaseConfigurator::class, new class extends DatabaseConfigurator
        {
            public function apply(array $credentials): string
            {
                return config('database.default');
            }
        });

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        parent::tearDown();
    }

    protected function inspectorReturns(array $result): void
    {
        $this->mock(DatabaseInspector::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn($result));
    }

    protected function connected(int $tables = 0): void
    {
        $this->inspectorReturns(['ok' => true, 'message' => 'Connected to MySQL 8.0.36.', 'server' => 'MySQL', 'version' => '8.0.36', 'existing_tables' => $tables]);
    }

    protected function license(string $key = 'md-ld-test-actv-aaaa-bbbb'): TestResponse
    {
        return $this->post('/install/license', ['license_key' => $key, 'url' => 'https://label.example.com/', 'accept' => '1']);
    }

    protected function walkToDatabaseStep(): void
    {
        $this->post('/install', ['terms' => '1'])->assertRedirect('/install/license');
        $this->license()->assertRedirect('/install/license');
        $this->post('/install/license/continue')->assertRedirect('/install/requirements');
        $this->post('/install/requirements')->assertRedirect('/install/database');
    }

    protected function databaseInput(array $overrides = []): array
    {
        return array_merge([
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'labeldesk',
            'username' => 'labeldesk', 'password' => 'S3cret "pass" #1', 'prefix' => '',
        ], $overrides);
    }

    protected function adminInput(array $overrides = []): array
    {
        return array_merge(['name' => 'Priya Admin', 'email' => 'Admin@Example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd'], $overrides);
    }

    protected function customizeInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Records', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'locale' => 'en', 'date_format' => 'd/m/Y',
            'theme' => 'ocean', 'custom_colors' => '1', 'brand_color' => '#FF5500', 'brand_color_2' => '#22d9a0',
            'font' => 'inter-tight', 'modes' => 'dark', 'default_mode' => 'dark', 'sidebar' => 'dark',
            'login_layout' => 'studio',
            'login' => ['headline' => 'Welcome to', 'accent' => 'Acme', 'headline_end' => 'HQ.', 'subtitle' => 'Our label workspace.', 'welcome' => 'Sign in', 'note' => 'Ask Priya for access.'],
            'logo' => UploadedFile::fake()->image('logo.png', 300, 80),
        ], $overrides);
    }

    public function test_license_is_step_zero_and_invalid_keys_block_everything(): void
    {
        $this->post('/install', ['terms' => '1'])->assertRedirect('/install/license');
        $this->get('/install/requirements')->assertRedirect('/install/license');

        $this->get('/install/license')->assertOk()->assertSee('Validate your license')->assertSee('Site address');
        $this->license('MD-LD-TEST-EXPD-AAAA-BBBB')->assertSessionHasErrors('license_key');
        $this->license('MD-LD-XXXX-XXXX-XXXX-XXXX')->assertSessionHasErrors('license_key');
        $this->post('/install/license', ['license_key' => 'MD-LD-TEST-ACTV-AAAA-BBBB', 'url' => 'not a url', 'accept' => '1'])->assertSessionHasErrors('url');
        $this->post('/install/license/continue')->assertSessionHasErrors('license_key');
        $this->get('/install/run')->assertRedirect('/install/license');
    }

    public function test_connection_test_explains_a_cloudflare_block(): void
    {
        Http::fake([
            'musicdesk.tech/api/license/v1/public-key' => Http::response(['public_key' => LicenseAuthority::PUBLIC_KEY]),
            'musicdesk.tech/api/license/v1/activate' => Http::response('<html>Just a moment...</html>', 403, ['Server' => 'cloudflare', 'cf-mitigated' => 'challenge', 'CF-RAY' => 'abc123-SIN']),
            'musicdesk.tech/cdn-cgi/trace' => Http::response('fl=1
ip=198.51.100.7
ts=1
'),
        ]);

        $this->post('/install', ['terms' => '1']);
        $this->post('/install/license/diagnose')->assertRedirect('/install/license');

        $this->get('/install/license')->assertOk()
            ->assertSee('This server cannot use the license server yet.')
            ->assertSee('198.51.100.7')
            ->assertSee('abc123-SIN')
            ->assertSee('Cloudflare is blocking license requests');
    }

    public function test_changing_the_site_address_needs_a_new_activation(): void
    {
        $this->walkToDatabaseStep();
        $this->post('/install/license', ['license_key' => 'MD-LD-TEST-EXPD-AAAA-BBBB', 'url' => 'https://other.example.com', 'accept' => '1']);

        // The earlier activation was for label.example.com: steps after the license are reset.
        $this->get('/install/database')->assertRedirect('/install/license');
    }

    public function test_database_connection_failure_is_reported_without_echoing_password(): void
    {
        $this->inspectorReturns(['ok' => false, 'message' => 'Access denied. Check the database username and password.']);
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput())
            ->assertSessionHasErrors('database')
            ->assertSessionMissing('_old_input.password');

        $this->get('/install/redis')->assertRedirect('/install/database');
    }

    public function test_non_empty_database_is_never_used(): void
    {
        $this->connected(12);
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput())->assertSessionHasErrors('database');
        $this->get('/install/redis')->assertRedirect('/install/database');
    }

    public function test_database_input_is_validated(): void
    {
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput(['host' => 'bad host;', 'port' => 0, 'prefix' => 'Bad-Prefix']))
            ->assertSessionHasErrors(['host', 'port', 'prefix']);
    }

    public function test_redis_is_tested_and_can_be_skipped(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput())->assertRedirect('/install/redis');

        $this->mock(RedisInspector::class, fn ($mock) => $mock->shouldReceive('inspect')->once()->andReturn(['ok' => false, 'message' => 'Could not connect to Redis: Connection refused']));
        $this->post('/install/redis', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0, 'password' => 'r3dis'])
            ->assertSessionHasErrors('redis');

        $this->get('/install/redis')->assertOk()->assertSee('Skip, use the database');
        $this->post('/install/redis/skip')->assertRedirect('/install/mail');
    }

    public function test_mail_can_be_tested_or_skipped(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/redis/skip');

        $this->post('/install/mail', ['host' => 'bad host', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'nope'])
            ->assertSessionHasErrors(['host', 'from_address']);

        // An unreachable SMTP server gives a readable error, never the password.
        $this->post('/install/mail', ['action' => 'test', 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'username' => 'u', 'password' => 'Sm7pSecret', 'from_address' => 'no-reply@example.com'])
            ->assertSessionHasErrors('mail');
        $this->assertStringNotContainsString('Sm7pSecret', (string) session('errors')?->first('mail'));

        $this->post('/install/mail/skip')->assertRedirect('/install/administrator');
    }

    public function test_weak_administrator_password_is_rejected(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/redis/skip');
        $this->post('/install/mail/skip');

        $this->post('/install/administrator', $this->adminInput(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
    }

    public function test_customisation_is_validated_and_previewed(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/redis/skip');
        $this->post('/install/mail/skip');
        $this->post('/install/administrator', $this->adminInput());

        $this->get('/install/customize')->assertOk()->assertSee('Make it yours')->assertSee('Spotlight')->assertSee('https://label.example.com');

        $this->post('/install/customize', $this->customizeInput(['brand_color' => 'red', 'login_layout' => 'carousel', 'name' => '<b>x</b>', 'logo' => null]))
            ->assertSessionHasErrors(['brand_color', 'login_layout', 'name']);

        // An SVG logo with a script is refused.
        $evil = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/install/customize', $this->customizeInput(['logo' => $evil]))->assertSessionHasErrors('logo');

        $this->post('/install/customize', $this->customizeInput(['action' => 'preview']))->assertRedirect('/install/customize/preview/studio');
        $this->get('/install/customize/preview/studio')->assertOk()
            ->assertSee('Acme')->assertSee('Our label workspace.')->assertSee('Ask Priya for access.')
            ->assertSee('ld-login--studio', false)->assertSee('#ff5500', false);
    }

    public function test_full_installation_applies_every_step(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();

        $this->post('/install/database', $this->databaseInput())->assertRedirect('/install/redis');
        $this->post('/install/redis/skip')->assertRedirect('/install/mail');

        Mail::fake();
        $this->post('/install/mail', [
            'action' => 'save', 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'mailer', 'password' => 'Sm7pSecret', 'from_address' => 'no-reply@example.com', 'from_name' => 'Acme Records',
        ])->assertRedirect('/install/administrator');

        $this->post('/install/administrator', $this->adminInput())->assertRedirect('/install/customize');
        $this->post('/install/customize', $this->customizeInput())->assertRedirect('/install/run');

        $this->get('/install/run')->assertOk()->assertSee('Acme Records')->assertSee('smtp.example.com')
            ->assertSee('Skipped — using the database')->assertDontSee('S3cret')->assertDontSee('Sm7pSecret');

        $response = $this->post('/install/run');
        $response->assertRedirect();
        $this->assertStringContainsString('/install/complete?token=', $response->headers->get('Location'));

        // Lock + database
        $this->assertTrue(app(InstallationState::class)->isInstalled());
        $this->assertTrue(Schema::hasTable('license_activations'));

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Str0ngPassw0rd', $admin->password));
        $this->assertTrue($admin->hasRole(SystemRole::SuperAdmin->value));
        $this->assertSame(count(SystemRole::cases()), Role::query()->count());

        $activation = LicenseActivation::query()->firstOrFail();
        $this->assertSame('label.example.com', $activation->domain);
        $this->assertSame('business', $activation->plan);
        $this->assertSame('MD-LD-TEST-ACTV-AAAA-BBBB', $activation->license_key);
        $this->assertNotSame('MD-LD-TEST-ACTV-AAAA-BBBB', $activation->getRawOriginal('license_key'), 'License key must be stored encrypted');

        // Settings from steps 4 and 6
        $settings = app(SettingsService::class);
        $settings->flush();
        $this->assertSame('INR', $settings->get('general.currency'));
        $this->assertSame('d/m/Y', $settings->get('general.date_format'));
        $this->assertSame('smtp', $settings->get('mail.mailer'));
        $this->assertSame('smtps', $settings->get('mail.scheme'));
        $this->assertSame('Sm7pSecret', $settings->get('mail.password'));
        $this->assertSame('ocean', $settings->get('appearance.theme'));
        $this->assertSame('#ff5500', $settings->get('appearance.brand_color'));
        $this->assertSame('dark', $settings->get('appearance.modes'));
        $this->assertSame('studio', $settings->get('appearance.login_layout'));
        $this->assertSame('Acme', $settings->get('appearance.login_accent'));
        $logo = (string) $settings->get('appearance.logo');
        $this->assertMatchesRegularExpression('/^logo-[a-z0-9]{16}\.png$/', $logo);
        Storage::disk('public')->assertExists('branding/'.$logo);

        // Environment file
        $env = Dotenv::parse((string) file_get_contents($this->envPath));
        $this->assertSame('S3cret "pass" #1', $env['DB_PASSWORD']);
        $this->assertSame('https://label.example.com', $env['APP_URL']);
        $this->assertSame('Acme Records', $env['APP_NAME']);
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('database', $env['SESSION_DRIVER']);
        $this->assertArrayNotHasKey('REDIS_HOST', $env);
        $this->assertSame('Asia/Kolkata', $env['LABELDESK_TIMEZONE']);

        // Completion page works exactly once, then the installer is gone.
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('LabelDesk is installed')->assertSee('admin@example.com');
        $this->get($response->headers->get('Location'))->assertNotFound();
        $this->get('/install')->assertNotFound();
    }

    public function test_redis_settings_are_written_to_the_environment(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());

        $this->mock(RedisInspector::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['ok' => true, 'message' => 'Connected to Redis 7.2.', 'version' => '7.2']));
        $this->post('/install/redis', [
            'host' => 'redis.internal', 'port' => 6380, 'password' => 'R3disPass', 'database' => 2, 'tls' => '1',
            'use_cache' => '1', 'use_sessions' => '0', 'use_queue' => '1',
        ])->assertRedirect('/install/mail');

        $this->post('/install/mail/skip');
        $this->post('/install/administrator', $this->adminInput());
        $this->post('/install/customize', $this->customizeInput(['logo' => null]));
        $this->post('/install/run')->assertRedirect();

        $env = Dotenv::parse((string) file_get_contents($this->envPath));
        $this->assertSame('redis.internal', $env['REDIS_HOST']);
        $this->assertSame('6380', $env['REDIS_PORT']);
        $this->assertSame('R3disPass', $env['REDIS_PASSWORD']);
        $this->assertSame('tls', $env['REDIS_SCHEME']);
        $this->assertSame('redis', $env['CACHE_STORE']);
        $this->assertSame('database', $env['SESSION_DRIVER']);
        $this->assertSame('redis', $env['QUEUE_CONNECTION']);
        $this->assertSame('log', app(SettingsService::class)->get('mail.mailer'));
    }

    public function test_installer_session_never_stores_plain_passwords(): void
    {
        $this->connected();
        $this->walkToDatabaseStep();
        $this->post('/install/database', $this->databaseInput());
        $this->post('/install/redis/skip');
        $this->post('/install/mail', ['action' => 'save', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'password' => 'Sm7pSecret', 'from_address' => 'no-reply@example.com']);
        $this->post('/install/administrator', $this->adminInput());

        $session = json_encode(session()->all());
        $this->assertStringNotContainsString('S3cret', $session);
        $this->assertStringNotContainsString('Sm7pSecret', $session);
        $this->assertStringNotContainsString('Str0ngPassw0rd', $session);

        $progress = (string) @file_get_contents(config('labeldesk.installer.progress_path'));
        $this->assertStringNotContainsString('S3cret', $progress);
    }
}

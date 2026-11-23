<?php

namespace App\Services\System;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Brings the database up to date after new LabelDesk files are uploaded:
 * forward-only migrations, idempotent seeders (new permissions etc.) and a
 * cache refresh. Never drops or rolls back anything.
 *
 * Runs on its own (AutoUpgrade middleware) the first time a page is opened
 * after the version changed, so customers on shared hosting without SSH never
 * need a terminal. `php artisan labeldesk:upgrade` and the button under
 * Administration → System do the same by hand.
 */
class UpgradeService
{
    /** Minutes before a failed automatic upgrade is tried again. */
    public const RETRY_AFTER_MINUTES = 15;

    /** @var resource|null held while this process upgrades */
    protected static $lock = null;

    public function __construct(
        protected Migrator $migrator,
        protected SettingsService $settings,
        protected ActivityLogger $activity,
    ) {}

    /**
     * @return list<string> Migration names not yet run
     */
    public function pendingMigrations(): array
    {
        if (! $this->migrator->repositoryExists()) {
            return [];
        }

        $files = $this->migrator->getMigrationFiles([database_path('migrations')]);
        $ran = $this->migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    public function installedVersion(): ?string
    {
        $version = $this->settings->get('system.installed_version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** Cheap check for every request: the files are a different version than the database. */
    public function versionChanged(): bool
    {
        return $this->installedVersion() !== (string) config('labeldesk.product.version');
    }

    public function needed(): bool
    {
        return $this->pendingMigrations() !== [] || $this->versionChanged();
    }

    /**
     * @return array{from: ?string, to: string, migrations: list<string>}
     */
    public function run(?User $user = null, bool $automatic = false): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $from = $this->installedVersion();
        $pending = $this->pendingMigrations();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('db:seed', ['--force' => true, '--class' => DatabaseSeeder::class]);
        $this->clearCaches();

        $to = (string) config('labeldesk.product.version');
        $this->settings->setMany([
            'system.installed_version' => $to,
            'system.upgraded_at' => now()->toIso8601String(),
        ]);
        $this->clearFailure();

        $this->activity->log('system.upgraded', __('Upgraded LabelDesk from :from to :to', ['from' => $from ?? '—', 'to' => $to]), null, [
            'migrations' => count($pending),
            'automatic' => $automatic,
        ], $user);

        return ['from' => $from, 'to' => $to, 'migrations' => $pending];
    }

    /**
     * config:clear, route:clear, cache:clear and optimize:clear (compiled views,
     * events too), then PHP's opcode cache so the new files are used at once.
     * The license cache lives in the database and is not touched.
     */
    public function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'cache:clear', 'optimize:clear'] as $command) {
            rescue(fn () => Artisan::call($command), report: false);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** @param  array<string, mixed>  $options */
    protected function artisan(string $command, array $options): void
    {
        if (Artisan::call($command, $options) !== 0) {
            throw new RuntimeException(__(':command failed: :output', ['command' => $command, 'output' => mb_substr(trim(Artisan::output()), 0, 500)]));
        }
    }

    // ------------------------------------------------------------ automatic upgrade

    /**
     * Take the upgrade lock (a file lock, so it works with every cache driver and is
     * released by the operating system if PHP is killed). False when another
     * request is upgrading already.
     */
    public function claim(): bool
    {
        if (self::$lock !== null) {
            return true;
        }
        $dir = storage_path('framework');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($dir.DIRECTORY_SEPARATOR.'labeldesk-upgrade.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        self::$lock = $handle;

        return true;
    }

    public function release(): void
    {
        if (self::$lock !== null) {
            flock(self::$lock, LOCK_UN);
            fclose(self::$lock);
            self::$lock = null;
        }
    }

    /** Runs after the response was sent (see AutoUpgrade). Never throws. */
    public function runAutomatic(): bool
    {
        try {
            $this->run(null, true);

            return true;
        } catch (Throwable $e) {
            Log::error('Automatic upgrade failed', ['version' => config('labeldesk.product.version'), 'error' => $e->getMessage()]);
            $this->recordFailure($e->getMessage());

            return false;
        } finally {
            $this->release();
        }
    }

    /** @return array{version: string, error: string, at: int}|null */
    public function failure(): ?array
    {
        $raw = @file_get_contents($this->failureFile());
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) && ($data['version'] ?? null) === config('labeldesk.product.version') ? $data : null;
    }

    /** A failed automatic upgrade waits RETRY_AFTER_MINUTES before it is tried again. */
    public function waitingAfterFailure(): bool
    {
        $failure = $this->failure();

        return $failure !== null && (int) $failure['at'] > now()->subMinutes(self::RETRY_AFTER_MINUTES)->timestamp;
    }

    public function recordFailure(string $error): void
    {
        @file_put_contents($this->failureFile(), json_encode([
            'version' => (string) config('labeldesk.product.version'),
            'error' => mb_substr($error, 0, 1000),
            'at' => now()->timestamp,
        ]), LOCK_EX);
    }

    public function clearFailure(): void
    {
        @unlink($this->failureFile());
    }

    protected function failureFile(): string
    {
        return storage_path('framework'.DIRECTORY_SEPARATOR.'labeldesk-upgrade-failed.json');
    }
}

<?php

namespace App\Services\System;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;

/**
 * Brings the database up to date after new LabelDesk files are uploaded:
 * forward-only migrations, idempotent seeders (new permissions etc.) and a
 * cache refresh. Never drops or rolls back anything.
 */
class UpgradeService
{
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

    public function needed(): bool
    {
        return $this->pendingMigrations() !== [] || $this->installedVersion() !== config('labeldesk.product.version');
    }

    /**
     * @return array{from: ?string, to: string, migrations: list<string>}
     */
    public function run(?User $user = null): array
    {
        @set_time_limit(0);

        $from = $this->installedVersion();
        $pending = $this->pendingMigrations();

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--force' => true, '--class' => DatabaseSeeder::class]);
        Artisan::call('optimize:clear');

        $to = (string) config('labeldesk.product.version');
        $this->settings->setMany(['system.installed_version' => $to, 'system.upgraded_at' => now()->toIso8601String()]);

        $this->activity->log('system.upgraded', __('Upgraded LabelDesk from :from to :to', ['from' => $from ?? '—', 'to' => $to]), null, [
            'migrations' => count($pending),
        ], $user);

        return ['from' => $from, 'to' => $to, 'migrations' => $pending];
    }
}

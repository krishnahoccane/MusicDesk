<?php

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\User;
use App\Services\Installation\InstallationState;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Recovery tool: recreates a missing installation lock for a database that is
 * already fully installed (e.g. the lock file was lost while moving hosting).
 * Requires shell/SSH or cron access, so it is not reachable from the web.
 */
class RestoreInstallationLock extends Command
{
    protected $signature = 'labeldesk:install:restore-lock';

    protected $description = 'Recreate the installation lock for an existing, fully installed LabelDesk database';

    public function handle(InstallationState $state, SettingsService $settings): int
    {
        if ($state->isInstalled()) {
            $this->info('The installation lock already exists. Nothing to do.');

            return self::SUCCESS;
        }

        try {
            $hasTables = Schema::hasTable('migrations') && Schema::hasTable('users') && Schema::hasTable('settings');
            $admin = $hasTables ? User::role(SystemRole::SuperAdmin->value)->first() : null;
        } catch (Throwable $e) {
            $this->error('Could not read the configured database: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $admin) {
            $this->error('The configured database does not contain a completed LabelDesk installation (no Super Admin found). Run the web installer instead.');

            return self::FAILURE;
        }

        $installationId = (string) ($settings->get('system.installation_id') ?? '');
        if ($installationId === '') {
            $this->error('The installation id is missing from settings; contact MusicDesk support.');

            return self::FAILURE;
        }

        $state->writeLock(['installation_id' => $installationId, 'administrator' => $admin->email, 'restored' => true]);
        $state->clearProgress();

        $this->info('Installation lock restored. The installer is disabled again.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Installation\InstallationState;
use App\Services\System\UpgradeService;
use Illuminate\Console\Command;

/**
 * Run after uploading a new LabelDesk version (also available as a button
 * in Administration → System). Take a backup first.
 */
class Upgrade extends Command
{
    protected $signature = 'labeldesk:upgrade {--force : Run without asking for confirmation}';

    protected $description = 'Upgrade the LabelDesk database after new files were uploaded (migrations, permissions, caches)';

    public function handle(InstallationState $installation, UpgradeService $upgrade): int
    {
        if (! $installation->isInstalled()) {
            $this->error('LabelDesk is not installed. Open the installer in your browser first.');

            return self::FAILURE;
        }

        $pending = $upgrade->pendingMigrations();
        $this->line('Installed version: '.($upgrade->installedVersion() ?? 'unknown'));
        $this->line('New version:       '.config('labeldesk.product.version'));
        $this->line('Pending database changes: '.count($pending));

        if (! $upgrade->needed()) {
            $this->info('LabelDesk is already up to date.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Have you taken a backup? Continue with the upgrade?', true)) {
            return self::FAILURE;
        }

        $result = $upgrade->run();
        $this->info("Upgraded to {$result['to']} (".count($result['migrations']).' database changes applied).');

        return self::SUCCESS;
    }
}

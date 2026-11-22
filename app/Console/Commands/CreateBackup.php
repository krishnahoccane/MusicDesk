<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Installation\InstallationState;
use App\Services\License\LicenseService;
use App\Support\Format;
use Illuminate\Console\Command;
use Throwable;

/**
 * Creates a backup (database + uploaded files). Scheduled nightly when
 * enabled in Backups (automatic tasks); can also be run over SSH.
 */
class CreateBackup extends Command
{
    protected $signature = 'labeldesk:backup
        {--database-only : Back up the database only, without uploaded files}
        {--scheduled : Only run when the nightly backup is enabled in Backups}';

    protected $description = 'Create a LabelDesk backup in storage/app/private/backups';

    public function handle(InstallationState $installation, BackupService $backups, LicenseService $license): int
    {
        if (! $installation->isInstalled()) {
            $this->warn('LabelDesk is not installed.');

            return self::SUCCESS;
        }

        if ($this->option('scheduled') && ! $backups->scheduled()) {
            return self::SUCCESS;
        }

        if (! $license->canUse('backups')) {
            $this->error('Backups are not included in this installation\'s license plan.');

            return self::FAILURE;
        }

        try {
            $result = $backups->create(! $this->option('database-only'), null, $this->option('scheduled') ? 'scheduled' : 'console');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info("Backup created: {$result['name']} (".Format::bytes($result['size']).", {$result['rows']} rows, {$result['files']} files)");

        return self::SUCCESS;
    }
}

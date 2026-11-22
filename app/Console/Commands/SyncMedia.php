<?php

namespace App\Console\Commands;

use App\Services\Installation\InstallationState;
use App\Services\MediaStorage\MediaSyncService;
use Illuminate\Console\Command;

/**
 * Uploads queued master audio and artwork to the connected cloud drive and
 * removes local copies that are due (Settings → Media storage).
 */
class SyncMedia extends Command
{
    protected $signature = 'labeldesk:media:sync {--budget=240 : Seconds to spend uploading before stopping} {--all : Queue every existing file first}';

    protected $description = 'Upload media files to Google Drive / OneDrive and remove local copies that are due';

    public function handle(InstallationState $installation, MediaSyncService $sync): int
    {
        if (! $installation->isInstalled()) {
            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $this->line('Queued '.$sync->queueAll().' files.');
        }

        $stats = $sync->run((int) $this->option('budget'));
        $this->info(sprintf('Processed %d, uploaded %d, failed %d, local copies removed %d.', $stats['processed'], $stats['uploaded'], $stats['failed'], $stats['removed']));

        return self::SUCCESS;
    }
}

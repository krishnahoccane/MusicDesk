<?php

namespace LabelDesk\Addons\MediaStorage\Console;

use App\Services\Installation\InstallationState;
use Illuminate\Console\Command;
use LabelDesk\Addons\MediaStorage\Services\MediaSyncService;

/**
 * Uploads queued master audio and artwork to every enabled Media storage destination and
 * removes local copies that are due (Settings → Media storage).
 */
class SyncMedia extends Command
{
    protected $signature = 'labeldesk:media:sync {--budget=240 : Seconds to spend uploading before stopping} {--all : Queue every existing file first}';

    protected $description = 'Upload master audio and artwork to the Media storage destinations and remove server copies that are due';

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

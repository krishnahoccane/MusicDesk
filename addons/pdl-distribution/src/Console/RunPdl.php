<?php

namespace LabelDesk\Addons\PdlDistribution\Console;

use Illuminate\Console\Command;
use LabelDesk\Addons\PdlDistribution\Services\DeliveryService;

/**
 * Same work as the "pdl" background task, for servers that prefer cron
 * (e.g. large masters on slow connections: --seconds=1800).
 */
class RunPdl extends Command
{
    protected $signature = 'labeldesk:pdl:run {--seconds=240 : Stop starting new work after this many seconds}';

    protected $description = 'Send queued releases to PDL and sync the status of sent ones';

    public function handle(DeliveryService $deliveries): int
    {
        $stats = $deliveries->run(max(10, (int) $this->option('seconds')));
        $this->info("Processed {$stats['processed']} deliveries, synced {$stats['synced']}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\RoyaltyImport;
use App\Services\Royalties\RoyaltyImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes a mapped statement import on the database queue (run every
 * minute by the scheduler, so no persistent worker is needed).
 */
class ProcessRoyaltyImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(RoyaltyImportService $imports): void
    {
        $import = RoyaltyImport::query()->with('period', 'creator')->find($this->importId);
        if ($import) {
            $imports->process($import);
        }
    }
}

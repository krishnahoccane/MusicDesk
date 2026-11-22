<?php

namespace App\Reports\Definitions;

use App\Enums\DeliveryStatus;
use App\Models\Dsp;
use App\Reports\Report;
use Illuminate\Support\Facades\DB;

/**
 * Store × delivery status counts.
 */
class StoreSummaryReport extends Report
{
    public function key(): string
    {
        return 'stores';
    }

    public function title(): string
    {
        return __('Distribution by store');
    }

    public function description(): string
    {
        return __('How many releases each store has, by delivery status.');
    }

    public function icon(): string
    {
        return 'store';
    }

    public function group(): string
    {
        return 'distribution';
    }

    public function features(): array
    {
        return ['advanced_reports', 'distribution'];
    }

    public function permissions(): array
    {
        return ['distribution.view'];
    }

    public function columns(array $filters): array
    {
        $columns = ['store' => ['label' => __('Store'), 'type' => 'text']];
        foreach (DeliveryStatus::cases() as $status) {
            $columns[$status->value] = ['label' => $status->label(), 'type' => 'int'];
        }

        return $columns + ['total' => ['label' => __('Total'), 'type' => 'int']];
    }

    public function totals(): array
    {
        return [...array_map(fn (DeliveryStatus $s) => $s->value, DeliveryStatus::cases()), 'total'];
    }

    public function rows(array $filters): iterable
    {
        $counts = [];
        foreach (DB::table('release_deliveries')->selectRaw('dsp_id, status, count(*) as aggregate')->groupBy('dsp_id', 'status')->get() as $row) {
            $counts[$row->dsp_id][$row->status] = (int) $row->aggregate;
        }

        foreach (Dsp::query()->orderBy('name')->get(['id', 'name']) as $dsp) {
            if (empty($counts[$dsp->id])) {
                continue;
            }

            $row = ['store' => $dsp->name];
            foreach (DeliveryStatus::cases() as $status) {
                $row[$status->value] = $counts[$dsp->id][$status->value] ?? 0;
            }

            yield $row + ['total' => array_sum($counts[$dsp->id])];
        }
    }
}

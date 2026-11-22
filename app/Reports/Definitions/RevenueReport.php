<?php

namespace App\Reports\Definitions;

use App\Models\RoyaltyPeriod;
use App\Reports\Report;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Royalty revenue grouped by period, artist, release, track, store or territory.
 *
 * Artist figures come from calculated earnings (artist and label portions);
 * the other groupings come from the imported statement lines (net revenue in
 * the label currency), so they include unmatched lines.
 */
class RevenueReport extends Report
{
    public function key(): string
    {
        return 'revenue';
    }

    public function title(): string
    {
        return __('Royalty revenue');
    }

    public function description(): string
    {
        return __('Net revenue by period, artist, release, track, store or territory.');
    }

    public function icon(): string
    {
        return 'trending-up';
    }

    public function group(): string
    {
        return 'royalties';
    }

    public function features(): array
    {
        return ['financial_reports', 'royalties'];
    }

    public function permissions(): array
    {
        return ['reports.financial', 'royalties.view'];
    }

    public function filters(): array
    {
        return [
            'by' => ['label' => __('Group by'), 'type' => 'select', 'default' => 'period', 'options' => [
                'period' => __('Period'), 'artist' => __('Artist'), 'release' => __('Release'),
                'track' => __('Track'), 'store' => __('Store'), 'territory' => __('Territory'),
            ]],
            'period' => ['label' => __('Period'), 'type' => 'select', 'default' => '', 'options' => ['' => __('All periods')]
                + RoyaltyPeriod::query()->orderByDesc('starts_on')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all()],
        ];
    }

    public function columns(array $filters): array
    {
        $by = $filters['by'] ?? 'period';
        $label = $this->filters()['by']['options'][$by] ?? __('Period');

        if ($by === 'artist') {
            return [
                'name' => ['label' => $label, 'type' => 'text'],
                'units' => ['label' => __('Units'), 'type' => 'int'],
                'revenue' => ['label' => __('Revenue'), 'type' => 'money'],
                'artist_amount' => ['label' => __('Artist earnings'), 'type' => 'money'],
                'label_amount' => ['label' => __('Label share'), 'type' => 'money'],
            ];
        }

        return array_filter([
            'name' => ['label' => $label, 'type' => 'text'],
            'code' => in_array($by, ['release', 'track'], true) ? ['label' => $by === 'track' ? __('ISRC') : __('UPC'), 'type' => 'text'] : null,
            'units' => ['label' => __('Units'), 'type' => 'int'],
            'revenue' => ['label' => __('Net revenue'), 'type' => 'money'],
            'share' => ['label' => __('Share of total'), 'type' => 'percent'],
        ]);
    }

    public function totals(): array
    {
        return ['units', 'revenue', 'artist_amount', 'label_amount', 'share'];
    }

    public function rows(array $filters): iterable
    {
        $by = $filters['by'] ?? 'period';
        $period = ($filters['period'] ?? '') !== '' ? (int) $filters['period'] : null;

        if ($by === 'artist') {
            $rows = DB::table('royalty_earnings')
                ->join('artists', 'artists.id', '=', 'royalty_earnings.artist_id')
                ->when($period, fn ($q) => $q->where('royalty_earnings.royalty_period_id', $period))
                ->groupBy('royalty_earnings.artist_id', 'artists.name')
                ->orderBy('artists.name')
                ->selectRaw('artists.name as name, sum(royalty_earnings.units) as units, sum(royalty_earnings.revenue) as revenue, sum(royalty_earnings.artist_amount) as artist_amount, sum(royalty_earnings.label_amount) as label_amount')
                ->get();

            foreach ($rows as $row) {
                yield [
                    'name' => $row->name,
                    'units' => (int) $row->units,
                    'revenue' => self::dec($row->revenue),
                    'artist_amount' => self::dec($row->artist_amount),
                    'label_amount' => self::dec($row->label_amount),
                ];
            }

            return;
        }

        $query = DB::table('royalty_lines')->when($period, fn ($q) => $q->where('royalty_lines.royalty_period_id', $period));

        match ($by) {
            'release' => $query->leftJoin('releases', 'releases.id', '=', 'royalty_lines.release_id')
                ->groupBy('royalty_lines.release_id', 'releases.title', 'releases.version', 'releases.upc')
                ->selectRaw('releases.title as name, releases.version as version, releases.upc as code')
                ->orderByRaw('releases.title is null')->orderBy('releases.title'),
            'track' => $query->leftJoin('tracks', 'tracks.id', '=', 'royalty_lines.track_id')
                ->groupBy('royalty_lines.track_id', 'tracks.title', 'tracks.version', 'tracks.isrc')
                ->selectRaw('tracks.title as name, tracks.version as version, tracks.isrc as code')
                ->orderByRaw('tracks.title is null')->orderBy('tracks.title'),
            'store' => $query->groupBy('royalty_lines.store')->selectRaw('royalty_lines.store as name')->orderBy('royalty_lines.store'),
            'territory' => $query->groupBy('royalty_lines.territory')->selectRaw('royalty_lines.territory as name')->orderBy('royalty_lines.territory'),
            default => $query->join('royalty_periods', 'royalty_periods.id', '=', 'royalty_lines.royalty_period_id')
                ->groupBy('royalty_lines.royalty_period_id', 'royalty_periods.name', 'royalty_periods.starts_on')
                ->selectRaw('royalty_periods.name as name')->orderBy('royalty_periods.starts_on'),
        };

        $rows = $query->selectRaw('sum(royalty_lines.units) as units, sum(royalty_lines.net_amount_base) as revenue')->get();
        $total = Decimal::sum($rows->map(fn ($r) => self::dec($r->revenue)));

        foreach ($rows as $row) {
            $revenue = self::dec($row->revenue);
            $name = $row->name;
            if ($name === null || $name === '') {
                $name = in_array($by, ['release', 'track'], true) ? __('Unmatched lines') : __('Not specified');
            } elseif (! empty($row->version)) {
                $name .= " ({$row->version})";
            }

            yield array_filter([
                'name' => $name,
                'code' => in_array($by, ['release', 'track'], true) ? ($row->code ?? '') : null,
                'units' => (int) $row->units,
                'revenue' => $revenue,
                'share' => Decimal::isZero($total) ? '0' : Decimal::round(Decimal::mul(Decimal::div($revenue, $total, 10), '100'), 2),
            ], fn ($v) => $v !== null);
        }
    }
}

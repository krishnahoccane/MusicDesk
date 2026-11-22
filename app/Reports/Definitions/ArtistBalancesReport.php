<?php

namespace App\Reports\Definitions;

use App\Enums\LedgerEntryType;
use App\Reports\Report;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Each artist's account at a date: earnings, recoupable costs, payments,
 * adjustments and the resulting balance.
 */
class ArtistBalancesReport extends Report
{
    public function key(): string
    {
        return 'artist-balances';
    }

    public function title(): string
    {
        return __('Artist balances');
    }

    public function description(): string
    {
        return __('What each artist has earned, been advanced and paid, and the balance owed.');
    }

    public function icon(): string
    {
        return 'scale';
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
        return ['reports.financial', 'payments.view'];
    }

    public function filters(): array
    {
        return [
            'show' => ['label' => __('Show'), 'type' => 'select', 'default' => '', 'options' => [
                '' => __('All artists with an account'), 'owed' => __('Owed to the artist'), 'unrecouped' => __('Unrecouped'),
            ]],
            'as_of' => ['label' => __('As of'), 'type' => 'date'],
        ];
    }

    public function columns(array $filters): array
    {
        return [
            'artist' => ['label' => __('Artist'), 'type' => 'text'],
            'earned' => ['label' => __('Earned'), 'type' => 'money'],
            'recoupable' => ['label' => __('Advances & expenses'), 'type' => 'money'],
            'paid' => ['label' => __('Paid'), 'type' => 'money'],
            'adjustments' => ['label' => __('Adjustments'), 'type' => 'money'],
            'balance' => ['label' => __('Balance'), 'type' => 'money'],
        ];
    }

    public function totals(): array
    {
        return ['earned', 'recoupable', 'paid', 'adjustments', 'balance'];
    }

    public function rows(array $filters): iterable
    {
        $sums = DB::table('artist_ledger_entries')
            ->join('artists', 'artists.id', '=', 'artist_ledger_entries.artist_id')
            ->when($filters['as_of'] ?? null, fn ($q, $v) => $q->whereDate('artist_ledger_entries.entry_date', '<=', $v))
            ->groupBy('artist_ledger_entries.artist_id', 'artists.name', 'artist_ledger_entries.type')
            ->orderBy('artists.name')
            ->selectRaw('artist_ledger_entries.artist_id as artist_id, artists.name as name, artist_ledger_entries.type as type, sum(artist_ledger_entries.amount) as amount')
            ->get()
            ->groupBy('artist_id');

        foreach ($sums as $entries) {
            $by = $entries->mapWithKeys(fn ($e) => [$e->type => self::dec($e->amount)]);
            $row = [
                'artist' => $entries->first()->name,
                'earned' => $by[LedgerEntryType::Earnings->value] ?? '0',
                'recoupable' => Decimal::sub('0', Decimal::add($by[LedgerEntryType::Advance->value] ?? '0', $by[LedgerEntryType::Expense->value] ?? '0')),
                'paid' => Decimal::sub('0', $by[LedgerEntryType::Payment->value] ?? '0'),
                'adjustments' => $by[LedgerEntryType::Adjustment->value] ?? '0',
                'balance' => Decimal::sum($by->values()),
                '_url' => route('artists.show', ['artist' => $entries->first()->artist_id, 'tab' => 'earnings']),
            ];

            $sign = Decimal::compare($row['balance'], '0');
            if ((($filters['show'] ?? '') === 'owed' && $sign <= 0) || (($filters['show'] ?? '') === 'unrecouped' && $sign >= 0)) {
                continue;
            }

            yield $row;
        }
    }
}

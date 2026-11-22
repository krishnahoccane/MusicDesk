<?php

namespace App\Reports\Definitions;

use App\Enums\LedgerEntryType;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Reports\Report;
use App\Support\Decimal;

class PaymentsReport extends Report
{
    public function key(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return __('Artist payments');
    }

    public function description(): string
    {
        return __('Payments made to artists, with method and reference.');
    }

    public function icon(): string
    {
        return 'banknote';
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
            'artist' => ['label' => __('Artist'), 'type' => 'select', 'default' => '', 'options' => ['' => __('All artists')]
                + Artist::withTrashed()->whereIn('id', ArtistLedgerEntry::query()->where('type', LedgerEntryType::Payment->value)->select('artist_id'))
                    ->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all()],
            'from' => ['label' => __('Paid from'), 'type' => 'date'],
            'to' => ['label' => __('Paid to'), 'type' => 'date'],
        ];
    }

    public function columns(array $filters): array
    {
        return [
            'date' => ['label' => __('Date'), 'type' => 'date'],
            'artist' => ['label' => __('Artist'), 'type' => 'text'],
            'amount' => ['label' => __('Amount'), 'type' => 'money'],
            'method' => ['label' => __('Method'), 'type' => 'text'],
            'reference' => ['label' => __('Reference'), 'type' => 'text'],
            'description' => ['label' => __('Description'), 'type' => 'text'],
        ];
    }

    public function totals(): array
    {
        return ['amount'];
    }

    public function rows(array $filters): iterable
    {
        $query = ArtistLedgerEntry::query()
            ->with(['artist' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->where('type', LedgerEntryType::Payment->value)
            ->when($filters['artist'] ?? '', fn ($q, $v) => $q->where('artist_id', (int) $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
            ->orderByDesc('entry_date')->orderByDesc('id');

        foreach ($query->lazy(200) as $entry) {
            yield [
                'date' => $entry->entry_date,
                'artist' => $entry->artist?->name,
                'amount' => Decimal::sub('0', (string) $entry->amount),
                'method' => $entry->method,
                'reference' => $entry->reference,
                'description' => $entry->description,
                '_url' => route('artists.show', ['artist' => $entry->artist_id, 'tab' => 'earnings']),
            ];
        }
    }
}

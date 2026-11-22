<?php

namespace App\Reports\Definitions;

use App\Enums\ContractStatus;
use App\Models\ArtistContract;
use App\Reports\Report;

class ContractsEndingReport extends Report
{
    public function key(): string
    {
        return 'contracts-ending';
    }

    public function title(): string
    {
        return __('Contracts ending soon');
    }

    public function description(): string
    {
        return __('Active artist contracts that end within the chosen number of days.');
    }

    public function icon(): string
    {
        return 'file-clock';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function features(): array
    {
        return ['reports', 'documents'];
    }

    public function permissions(): array
    {
        return ['contracts.view'];
    }

    public function filters(): array
    {
        return [
            'within' => ['label' => __('Ending within'), 'type' => 'select', 'default' => '90', 'options' => [
                '30' => __(':n days', ['n' => 30]), '60' => __(':n days', ['n' => 60]), '90' => __(':n days', ['n' => 90]),
                '180' => __(':n days', ['n' => 180]), '365' => __('1 year'),
            ]],
            'ended' => ['label' => __('Include'), 'type' => 'select', 'default' => '', 'options' => [
                '' => __('Upcoming only'), 'recent' => __('Also ended in the last 90 days'),
            ]],
        ];
    }

    public function columns(array $filters): array
    {
        return [
            'artist' => ['label' => __('Artist'), 'type' => 'text'],
            'contract' => ['label' => __('Contract'), 'type' => 'text'],
            'type' => ['label' => __('Type'), 'type' => 'text'],
            'status' => ['label' => __('Status'), 'type' => 'badge'],
            'start_date' => ['label' => __('Start'), 'type' => 'date'],
            'end_date' => ['label' => __('End'), 'type' => 'date'],
            'days' => ['label' => __('Days left'), 'type' => 'int'],
        ];
    }

    public function rows(array $filters): iterable
    {
        $today = ArtistContract::today();
        $from = ($filters['ended'] ?? '') === 'recent' ? $today->copy()->subDays(90) : $today;
        $to = $today->copy()->addDays((int) ($filters['within'] ?? 90));

        $query = ArtistContract::query()
            ->with(['artist' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->where('status', ContractStatus::Active->value)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('end_date', '<=', $to->toDateString())
            ->orderBy('end_date')->orderBy('id');

        foreach ($query->lazy(200) as $contract) {
            $status = $contract->effectiveStatus();

            yield [
                'artist' => $contract->artist?->name,
                'contract' => $contract->title,
                'type' => $contract->type?->label(),
                'status' => [$status->label(), $status->variant()],
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
                'days' => (int) $today->diffInDays($contract->end_date, false),
                '_url' => route('artists.show', ['artist' => $contract->artist_id, 'tab' => 'contracts']),
            ];
        }
    }
}

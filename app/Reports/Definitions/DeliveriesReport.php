<?php

namespace App\Reports\Definitions;

use App\Enums\DeliveryStatus;
use App\Models\Dsp;
use App\Models\ReleaseDelivery;
use App\Reports\Report;

class DeliveriesReport extends Report
{
    public function key(): string
    {
        return 'deliveries';
    }

    public function title(): string
    {
        return __('Store deliveries');
    }

    public function description(): string
    {
        return __('Every release sent to a store with its delivery status and dates.');
    }

    public function icon(): string
    {
        return 'send';
    }

    public function group(): string
    {
        return 'distribution';
    }

    public function features(): array
    {
        return ['reports', 'distribution'];
    }

    public function permissions(): array
    {
        return ['distribution.view'];
    }

    public function filters(): array
    {
        return [
            'store' => ['label' => __('Store'), 'type' => 'select', 'default' => '', 'options' => ['' => __('All stores')]
                + Dsp::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all()],
            'status' => ['label' => __('Status'), 'type' => 'select', 'default' => '', 'options' => ['' => __('Any status')]
                + collect(DeliveryStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()],
            'from' => ['label' => __('Delivered from'), 'type' => 'date'],
            'to' => ['label' => __('Delivered to'), 'type' => 'date'],
        ];
    }

    public function columns(array $filters): array
    {
        return [
            'release' => ['label' => __('Release'), 'type' => 'text'],
            'artists' => ['label' => __('Artists'), 'type' => 'text'],
            'store' => ['label' => __('Store'), 'type' => 'text'],
            'status' => ['label' => __('Status'), 'type' => 'badge'],
            'delivered_on' => ['label' => __('Delivered'), 'type' => 'date'],
            'live_on' => ['label' => __('Live'), 'type' => 'date'],
            'store_id' => ['label' => __('Store ID'), 'type' => 'text'],
        ];
    }

    public function rows(array $filters): iterable
    {
        $query = ReleaseDelivery::query()
            ->with(['release' => fn ($q) => $q->withTrashed()->with('artists:artists.id,artists.name'), 'dsp:id,name'])
            ->when($filters['store'] ?? '', fn ($q, $v) => $q->where('dsp_id', (int) $v))
            ->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('delivered_on', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('delivered_on', '<=', $v));

        foreach ($query->lazyByIdDesc(200) as $delivery) {
            yield [
                'release' => $delivery->release?->displayTitle(),
                'artists' => $delivery->release?->artistLine(),
                'store' => $delivery->dsp?->name,
                'status' => [$delivery->status->label(), $delivery->status->variant()],
                'delivered_on' => $delivery->delivered_on,
                'live_on' => $delivery->live_on,
                'store_id' => $delivery->store_id,
                '_url' => $delivery->release ? route('releases.show', $delivery->release) : null,
            ];
        }
    }
}

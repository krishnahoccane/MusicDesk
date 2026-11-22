<?php

namespace App\Reports\Definitions;

use App\Enums\DeliveryStatus;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Models\Release;
use App\Reports\Report;

class ReleasesReport extends Report
{
    public function key(): string
    {
        return 'releases';
    }

    public function title(): string
    {
        return __('Releases by status and date');
    }

    public function description(): string
    {
        return __('Every release with its status, release date, codes, track count and live stores.');
    }

    public function icon(): string
    {
        return 'disc-3';
    }

    public function features(): array
    {
        return ['reports', 'releases'];
    }

    public function permissions(): array
    {
        return ['releases.view'];
    }

    public function filters(): array
    {
        return [
            'status' => ['label' => __('Status'), 'type' => 'select', 'default' => '', 'options' => ['' => __('Any status')]
                + collect(ReleaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()],
            'type' => ['label' => __('Type'), 'type' => 'select', 'default' => '', 'options' => ['' => __('Any type')]
                + collect(ReleaseType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()],
            'from' => ['label' => __('Release date from'), 'type' => 'date'],
            'to' => ['label' => __('Release date to'), 'type' => 'date'],
        ];
    }

    public function columns(array $filters): array
    {
        return [
            'release' => ['label' => __('Release'), 'type' => 'text'],
            'artists' => ['label' => __('Artists'), 'type' => 'text'],
            'type' => ['label' => __('Type'), 'type' => 'text'],
            'status' => ['label' => __('Status'), 'type' => 'badge'],
            'release_date' => ['label' => __('Release date'), 'type' => 'date'],
            'upc' => ['label' => __('UPC'), 'type' => 'text'],
            'catalog_number' => ['label' => __('Catalogue no.'), 'type' => 'text'],
            'tracks' => ['label' => __('Tracks'), 'type' => 'int'],
            'live' => ['label' => __('Live stores'), 'type' => 'int'],
        ];
    }

    public function totals(): array
    {
        return ['tracks', 'live'];
    }

    public function rows(array $filters): iterable
    {
        $query = Release::query()
            ->with('artists:artists.id,artists.name')
            ->withCount(['tracks', 'deliveries as live_count' => fn ($q) => $q->where('status', DeliveryStatus::Live->value)])
            ->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? '', fn ($q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('release_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('release_date', '<=', $v))
            ->orderByRaw('release_date is null')->orderByDesc('release_date')->orderBy('id');

        foreach ($query->lazy(200) as $release) {
            yield [
                'release' => $release->displayTitle(),
                'artists' => $release->artistLine(),
                'type' => $release->type->label(),
                'status' => [$release->status->label(), $release->status->variant()],
                'release_date' => $release->release_date,
                'upc' => $release->upc,
                'catalog_number' => $release->catalog_number,
                'tracks' => (int) $release->tracks_count,
                'live' => (int) $release->live_count,
                '_url' => route('releases.show', $release),
            ];
        }
    }
}

<?php

namespace App\Reports\Definitions;

use App\Enums\ReleaseStatus;
use App\Models\Track;
use App\Reports\Report;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracks that are not ready for delivery: no audio, no ISRC or no credits.
 */
class CatalogueGapsReport extends Report
{
    public function key(): string
    {
        return 'catalogue-gaps';
    }

    public function title(): string
    {
        return __('Catalogue gaps');
    }

    public function description(): string
    {
        return __('Tracks missing master audio, an ISRC or songwriter and producer credits.');
    }

    public function icon(): string
    {
        return 'list-checks';
    }

    public function features(): array
    {
        return ['advanced_reports', 'tracks'];
    }

    public function permissions(): array
    {
        return ['tracks.view'];
    }

    public function filters(): array
    {
        return [
            'missing' => ['label' => __('Missing'), 'type' => 'select', 'default' => 'any', 'options' => [
                'any' => __('Anything'), 'audio' => __('Master audio'), 'isrc' => __('ISRC'), 'credits' => __('Credits'),
            ]],
            'status' => ['label' => __('Release status'), 'type' => 'select', 'default' => '', 'options' => ['' => __('Any status')]
                + collect(ReleaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()],
        ];
    }

    public function columns(array $filters): array
    {
        return [
            'track' => ['label' => __('Track'), 'type' => 'text'],
            'release' => ['label' => __('Release'), 'type' => 'text'],
            'artists' => ['label' => __('Artists'), 'type' => 'text'],
            'status' => ['label' => __('Release status'), 'type' => 'badge'],
            'missing' => ['label' => __('Missing'), 'type' => 'text'],
        ];
    }

    public function rows(array $filters): iterable
    {
        $missing = $filters['missing'] ?? 'any';

        $query = Track::query()
            ->with(['artists:artists.id,artists.name', 'release:id,title,version,status'])
            ->withCount('credits')
            ->whereHas('release', fn (Builder $q) => $q->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v)))
            ->where(function (Builder $q) use ($missing) {
                if (in_array($missing, ['any', 'audio'], true)) {
                    $q->orWhereNull('audio_path');
                }
                if (in_array($missing, ['any', 'isrc'], true)) {
                    $q->orWhereNull('isrc')->orWhere('isrc', '');
                }
                if (in_array($missing, ['any', 'credits'], true)) {
                    $q->orWhereDoesntHave('credits');
                }
            })
            ->orderBy('release_id')->orderBy('disc_number')->orderBy('position');

        foreach ($query->lazy(200) as $track) {
            $gaps = array_filter([
                $track->audio_path ? null : __('Audio'),
                filled($track->isrc) ? null : __('ISRC'),
                $track->credits_count > 0 ? null : __('Credits'),
            ]);

            yield [
                'track' => $track->displayTitle(),
                'release' => $track->release?->displayTitle(),
                'artists' => $track->artistLine(),
                'status' => $track->release ? [$track->release->status->label(), $track->release->status->variant()] : null,
                'missing' => implode(', ', $gaps),
                '_url' => route('tracks.show', $track),
            ];
        }
    }
}

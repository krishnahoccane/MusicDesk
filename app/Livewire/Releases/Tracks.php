<?php

namespace App\Livewire\Releases;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Release;
use App\Models\Track;
use App\Services\Catalog\TrackService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Track list of a release (tracks tab): order, delete, play.
 */
class Tracks extends Component
{
    use EnforcesLicense;

    #[Locked]
    public Release $release;

    public function mount(Release $release): void
    {
        $this->ensureFeature('tracks');
        $this->authorize('viewAny', Track::class);
        $this->release = $release;
    }

    #[On('release-updated')]
    public function refreshRelease(): void
    {
        $this->release->refresh();
    }

    public function move(int $id, int $direction, TrackService $tracks): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('tracks');
        $track = $this->find($id);
        $this->authorize('update', $track);

        try {
            $tracks->move(auth()->user(), $track, $direction);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        }
    }

    public function delete(int $id, TrackService $tracks): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('tracks');
        $track = $this->find($id);
        $this->authorize('delete', $track);

        try {
            $tracks->delete(auth()->user(), $track);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', message: __('Track deleted.'), type: 'success');
        $this->dispatch('release-updated');
    }

    protected function find(int $id): Track
    {
        return Track::query()->where('release_id', $this->release->id)->find($id) ?? abort(404);
    }

    public function render(): View
    {
        $tracks = $this->release->tracks()
            ->with(['artists:artists.id,artists.name', 'release'])
            ->withCount('credits')
            ->get();

        return view('livewire.releases.tracks', [
            'tracks' => $tracks,
            'totalDuration' => $tracks->sum('duration_seconds'),
            'canAdd' => auth()->user()->can('create', [Track::class, $this->release]),
            'canEdit' => auth()->user()->can('tracks.update') && $this->release->isEditable(),
            'canDelete' => auth()->user()->can('tracks.delete') && $this->release->isEditable(),
            'canPlay' => auth()->user()->can('tracks.download_master'),
        ]);
    }
}

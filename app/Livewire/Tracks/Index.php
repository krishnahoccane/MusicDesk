<?php

namespace App\Livewire\Tracks;

use App\Enums\ReleaseStatus;
use App\Models\Track;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catalog → Tracks: every recording across releases.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $audio = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Track::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'audio'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'audio');
        $this->resetPage();
    }

    public function render(): View
    {
        $tracks = Track::query()
            ->with(['artists:artists.id,artists.name', 'release:id,title,version,status,artwork_path,artwork_thumb_path,deleted_at'])
            ->whereHas('release', fn (Builder $r) => $r->whereNull('releases.deleted_at')
                ->when(in_array($this->status, ReleaseStatus::values(), true), fn ($q) => $q->where('status', $this->status)))
            ->when($this->search !== '', fn ($q) => $q->search($this->search))
            ->when($this->audio === 'missing', fn ($q) => $q->whereNull('audio_path'))
            ->when($this->audio === 'present', fn ($q) => $q->whereNotNull('audio_path'))
            ->orderBy('title')
            ->paginate(25);

        return view('livewire.tracks.index', [
            'tracks' => $tracks,
            'statuses' => ReleaseStatus::options(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->audio !== '',
        ])->title(__('Tracks'))->layoutData(['breadcrumbs' => [
            ['label' => __('Catalog')], ['label' => __('Tracks')],
        ]]);
    }
}

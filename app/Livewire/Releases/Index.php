<?php

namespace App\Livewire\Releases;

use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Genre;
use App\Models\Release;
use App\Services\Catalog\ReleaseService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class Index extends Component
{
    use EnforcesLicense, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $genre = '';

    #[Url(except: 'date')]
    public string $sort = 'date';

    public function mount(): void
    {
        $this->authorize('viewAny', Release::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type', 'genre', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'type', 'genre');
        $this->resetPage();
    }

    public function restore(int $id, ReleaseService $releases): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        $release = Release::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $release);

        $releases->restore(auth()->user(), $release);
        $this->dispatch('toast', message: __(':title has been restored.', ['title' => $release->displayTitle()]), type: 'success');
    }

    public function delete(int $id, ReleaseService $releases): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        $release = Release::query()->findOrFail($id);
        $this->authorize('delete', $release);

        try {
            $releases->delete(auth()->user(), $release);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }
        $this->dispatch('toast', message: __(':title has been deleted.', ['title' => $release->displayTitle()]), type: 'success');
    }

    public function render(): View
    {
        $query = $this->status === 'deleted' ? Release::onlyTrashed() : Release::query();

        $releases = $query
            ->with(['artists:artists.id,artists.name', 'genre:id,name'])
            ->withCount('tracks')
            ->when($this->search !== '', fn ($q) => $q->search($this->search))
            ->when(in_array($this->status, ReleaseStatus::values(), true), fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->type, ReleaseType::values(), true), fn ($q) => $q->where('type', $this->type))
            ->when(ctype_digit($this->genre), fn ($q) => $q->where('genre_id', (int) $this->genre))
            ->when($this->sort === 'title', fn ($q) => $q->orderBy('title'))
            ->when($this->sort === 'newest', fn ($q) => $q->latest('id'))
            ->when($this->sort === 'date', fn ($q) => $q->orderByRaw('release_date is null')->orderByDesc('release_date')->latest('id'))
            ->paginate(20);

        return view('livewire.releases.index', [
            'releases' => $releases,
            'statuses' => ReleaseStatus::options(),
            'types' => ReleaseType::options(),
            'genres' => Genre::query()->orderBy('name')->pluck('name', 'id'),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->type !== '' || $this->genre !== '',
            'total' => Release::query()->count(),
            'awaitingReview' => auth()->user()->can('releases.review')
                ? Release::query()->whereIn('status', [ReleaseStatus::Submitted->value, ReleaseStatus::InReview->value])->count()
                : 0,
        ])->title(__('Releases'))->layoutData(['breadcrumbs' => [
            ['label' => __('Catalog')], ['label' => __('Releases')],
        ]]);
    }
}

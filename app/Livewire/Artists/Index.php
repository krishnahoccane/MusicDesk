<?php

namespace App\Livewire\Artists;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Exceptions\CatalogException;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Services\ArtistService;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
    public string $country = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public function mount(): void
    {
        $this->authorize('viewAny', Artist::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type', 'country', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'type', 'country');
        $this->resetPage();
    }

    public function delete(int $id, ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $artist = Artist::query()->findOrFail($id);
        $this->authorize('delete', $artist);

        try {
            $artists->delete(auth()->user(), $artist);
        } catch (CatalogException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }
        $this->dispatch('toast', message: __(':name has been deleted. You can restore it from the Deleted filter.', ['name' => $artist->name]), type: 'success');
    }

    public function restore(int $id, ArtistService $artists): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('artists');
        $artist = Artist::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $artist);

        $artists->restore(auth()->user(), $artist);
        $this->dispatch('toast', message: __(':name has been restored.', ['name' => $artist->name]), type: 'success');
    }

    public function render(): View
    {
        $query = $this->status === 'deleted' ? Artist::onlyTrashed() : Artist::query();

        $artists = $query
            ->withCount(['contracts as active_contracts_count' => fn ($q) => $q->currentlyActive()])
            ->when($this->search !== '', fn ($q) => $q->search($this->search))
            ->when(in_array($this->status, ArtistStatus::values(), true), fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->type, ArtistType::values(), true), fn ($q) => $q->where('type', $this->type))
            ->when($this->country !== '' && Countries::name($this->country), fn ($q) => $q->where('country', strtoupper($this->country)))
            ->when($this->sort === 'newest', fn ($q) => $q->latest('id'), fn ($q) => $q->orderBy('name'))
            ->paginate(20);

        return view('livewire.artists.index', [
            'artists' => $artists,
            'statuses' => ArtistStatus::options(),
            'types' => ArtistType::options(),
            'countries' => Countries::options(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->type !== '' || $this->country !== '',
            'total' => Artist::query()->count(),
        ])->title(__('Artists'))->layoutData(['breadcrumbs' => [
            ['label' => __('Catalog')], ['label' => __('Artists')],
        ]]);
    }
}

<?php

namespace App\Livewire\Contracts;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\ArtistContract;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Business → Contracts: every artist contract in one list, with filters for
 * status, type and contracts ending soon. Editing happens on the artist page.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('contracts.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'type');
        $this->resetPage();
    }

    public function render(): View
    {
        $today = ArtistContract::today()->toDateString();

        $contracts = ArtistContract::query()
            ->with('artist:id,name,photo_path,deleted_at')
            ->whereHas('artist', fn (Builder $a) => $a->whereNull('artists.deleted_at')) // hide contracts of deleted artists
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $term)
                    ->orWhereHas('artist', fn (Builder $a) => $a->where('name', 'like', $term)));
            })
            ->when(in_array($this->type, ContractType::values(), true), fn ($q) => $q->where('type', $this->type))
            ->when($this->status === 'expiring', fn ($q) => $q->expiringWithin(60))
            ->when($this->status === ContractStatus::Active->value, fn ($q) => $q->currentlyActive())
            ->when($this->status === ContractStatus::Expired->value, fn ($q) => $q->where(fn (Builder $w) => $w
                ->where('status', ContractStatus::Expired->value)
                ->orWhere(fn (Builder $a) => $a->where('status', ContractStatus::Active->value)->where('end_date', '<', $today))))
            ->when(in_array($this->status, [ContractStatus::Draft->value, ContractStatus::Terminated->value], true), fn ($q) => $q->where('status', $this->status))
            ->orderByRaw('end_date is null')
            ->orderBy('end_date')
            ->paginate(25);

        return view('livewire.contracts.index', [
            'contracts' => $contracts,
            'types' => ContractType::options(),
            'statuses' => ContractStatus::options(),
            'expiringCount' => ArtistContract::query()->whereHas('artist', fn (Builder $a) => $a->whereNull('artists.deleted_at'))->expiringWithin(60)->count(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->type !== '',
        ])->title(__('Contracts'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Contracts')],
        ]]);
    }
}

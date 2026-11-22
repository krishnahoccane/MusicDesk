<?php

namespace App\Livewire\Distribution;

use App\Enums\DeliveryStatus;
use App\Enums\ReleaseStatus;
use App\Models\Dsp;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Business → Distribution: every release × store, with status filters and
 * releases that are ready but not yet sent anywhere.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $dsp = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('distribution.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'dsp'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'dsp');
        $this->resetPage();
    }

    public function render(): View
    {
        $live = fn (Builder $r) => $r->whereNull('releases.deleted_at');

        $deliveries = ReleaseDelivery::query()
            ->with(['dsp:id,name,lead_time_days', 'release' => fn ($q) => $q->with('artists:artists.id,artists.name')])
            ->whereHas('release', $live)
            ->when($this->search !== '', fn ($q) => $q->whereHas('release', fn (Builder $r) => $r->search($this->search)))
            ->when(in_array($this->status, DeliveryStatus::values(), true), fn ($q) => $q->where('status', $this->status))
            ->when(ctype_digit($this->dsp), fn ($q) => $q->where('dsp_id', (int) $this->dsp))
            ->latest('updated_at')
            ->paginate(25);

        $counts = ReleaseDelivery::query()->whereHas('release', $live)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('livewire.distribution.index', [
            'deliveries' => $deliveries,
            'counts' => $counts,
            'statuses' => DeliveryStatus::cases(),
            'dsps' => Dsp::query()->orderBy('name')->pluck('name', 'id'),
            'awaiting' => Release::query()
                ->with('artists:artists.id,artists.name')
                ->where('status', ReleaseStatus::Ready->value)
                ->whereDoesntHave('deliveries')
                ->orderBy('release_date')
                ->limit(10)
                ->get(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->dsp !== '',
        ])->title(__('Distribution'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Distribution')],
        ]]);
    }
}

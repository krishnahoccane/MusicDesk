<?php

namespace LabelDesk\Addons\PdlDistribution\Livewire;

use App\Enums\ReleaseStatus;
use App\Models\Release;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use LabelDesk\Addons\PdlDistribution\Models\PdlDelivery;
use LabelDesk\Addons\PdlDistribution\Services\PdlSettings;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Business → PDL distribution: every release sent to PDL with its status,
 * and approved / ready releases not sent yet.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('pdl.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(PdlSettings $settings): View
    {
        $visible = fn (Builder $r) => $r->whereNull('releases.deleted_at')
            ->when($this->search !== '', fn (Builder $q) => $q->search($this->search));

        $deliveries = PdlDelivery::query()
            ->with(['release' => fn ($q) => $q->with('artists:artists.id,artists.name')])
            ->whereHas('release', $visible)
            ->when(array_key_exists($this->status, PdlDelivery::STATUSES), fn ($q) => $q->where('status', $this->status))
            ->latest('updated_at')
            ->paginate(25);

        return view('pdl-distribution::index', [
            'deliveries' => $deliveries,
            'counts' => PdlDelivery::query()->whereHas('release', fn ($r) => $r->whereNull('releases.deleted_at'))
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'awaiting' => Release::query()
                ->with('artists:artists.id,artists.name')
                ->whereIn('status', [ReleaseStatus::Approved->value, ReleaseStatus::Ready->value])
                ->whereNotIn('id', PdlDelivery::query()->select('release_id'))
                ->when($this->search !== '', fn (Builder $q) => $q->search($this->search))
                ->orderBy('release_date')->limit(10)->get(),
            'configured' => $settings->isEnabled(),
        ])->title(__('PDL distribution'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('PDL distribution')],
        ]]);
    }
}

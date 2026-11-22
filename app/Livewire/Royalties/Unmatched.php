<?php

namespace App\Livewire\Royalties;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Models\Track;
use App\Services\Royalties\RoyaltyImportService;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Sales lines that did not match the catalogue, grouped by ISRC/UPC, with
 * manual matching to a track or "not ours".
 */
class Unmatched extends Component
{
    use EnforcesLicense;

    #[Locked]
    public RoyaltyPeriod $period;

    #[Locked]
    public ?string $matchingKey = null;

    #[Locked]
    public ?string $matchingKind = null;

    public string $trackSearch = '';

    public function mount(RoyaltyPeriod $period): void
    {
        abort_unless(auth()->user()->can('royalties.view'), 403);
        $this->period = $period;
    }

    protected function ensureManage(): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('royalties.manage'), 403);
    }

    public function startMatching(string $kind, string $key): void
    {
        $this->ensureManage();
        $this->matchingKind = $kind === 'upc' ? 'upc' : 'isrc';
        $this->matchingKey = $key;
        $this->trackSearch = '';
    }

    public function cancel(): void
    {
        $this->reset('matchingKey', 'matchingKind', 'trackSearch');
    }

    public function assign(int $trackId, RoyaltyImportService $imports): void
    {
        $this->ensureManage();
        $track = Track::query()->findOrFail($trackId);

        try {
            $count = $imports->match(auth()->user(), $this->period, (string) $this->matchingKind, (string) $this->matchingKey, $track);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->cancel();
        $this->dispatch('toast', message: trans_choice('{1} 1 line matched to ":track".|[0,*] :count lines matched to ":track".', $count, ['count' => $count, 'track' => $track->displayTitle()]), type: 'success');
        $this->dispatch('period-updated');
    }

    public function ignore(string $kind, string $key, RoyaltyImportService $imports): void
    {
        $this->ensureManage();

        try {
            $count = $imports->ignore(auth()->user(), $this->period, $kind, $key);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', message: trans_choice('{1} 1 line marked as not ours.|[0,*] :count lines marked as not ours.', $count, ['count' => $count]), type: 'success');
        $this->dispatch('period-updated');
    }

    public function render(): View
    {
        $base = RoyaltyLine::query()->where('royalty_period_id', $this->period->id)->where('match_status', 'unmatched');

        // Group by ISRC when present, otherwise by UPC.
        $groups = (clone $base)
            ->selectRaw("case when isrc is not null then 'isrc' else 'upc' end as kind, coalesce(isrc, upc) as id_key, count(*) as line_count, sum(units) as units, min(store) as store")
            ->groupByRaw("case when isrc is not null then 'isrc' else 'upc' end, coalesce(isrc, upc)")
            ->orderByDesc('line_count')
            ->limit(100)
            ->get()
            ->map(function ($g) use ($base) {
                $column = $g->kind === 'upc' ? 'upc' : 'isrc';
                $g->amount = Decimal::sum((clone $base)->where($column, $g->id_key)->when($column === 'upc', fn ($q) => $q->whereNull('isrc'))->pluck('net_amount_base')->map(fn ($v) => (string) $v));

                return $g;
            });

        $results = collect();
        if ($this->matchingKey !== null && mb_strlen(trim($this->trackSearch)) >= 2) {
            $results = Track::query()->with(['release:id,title,version,deleted_at', 'artists:artists.id,artists.name'])
                ->search(trim($this->trackSearch))->limit(10)->get();
        }

        return view('livewire.royalties.unmatched', [
            'groups' => $groups,
            'results' => $results,
            'canManage' => auth()->user()->can('royalties.manage') && ! $this->period->isLocked(),
            'currency' => config('labeldesk.currency'),
        ]);
    }
}

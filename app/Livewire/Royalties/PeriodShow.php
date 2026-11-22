<?php

namespace App\Livewire\Royalties;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Models\RoyaltyEarning;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Services\Royalties\RoyaltyCalculator;
use App\Services\Royalties\RoyaltyPeriodService;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * A royalty period: statements, unmatched sales and earnings per artist.
 */
class PeriodShow extends Component
{
    use EnforcesLicense;

    public RoyaltyPeriod $period;

    #[Url(except: 'imports')]
    public string $tab = 'imports';

    public function mount(RoyaltyPeriod $period): void
    {
        abort_unless(auth()->user()->can('royalties.view'), 403);
        $this->period = $period;
        if (! in_array($this->tab, ['imports', 'unmatched', 'earnings'], true)) {
            $this->tab = 'imports';
        }
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['imports', 'unmatched', 'earnings'], true) ? $tab : 'imports';
    }

    #[On('period-updated')]
    public function refreshPeriod(): void
    {
        $this->period->refresh();
    }

    protected function ensureManage(): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('royalties.manage'), 403);
    }

    public function recalculate(RoyaltyCalculator $calculator): void
    {
        $this->ensureManage();

        try {
            $result = $calculator->recalculate($this->period);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', message: __('Recalculated :count matched lines.', ['count' => number_format($result['lines'])]), type: 'success');
    }

    public function lock(RoyaltyPeriodService $periods): void
    {
        $this->ensureManage();

        try {
            $count = $periods->lock(auth()->user(), $this->period);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->period->refresh();
        $this->dispatch('toast', message: trans_choice('{0} Period locked. No artist earnings to post.|{1} Period locked. Earnings were posted to 1 artist.|[2,*] Period locked. Earnings were posted to :count artists.', $count, ['count' => $count]), type: 'success');
        $this->dispatch('period-updated');
    }

    public function render(): View
    {
        $totals = RoyaltyPeriodService::totals($this->period);
        $unmatchedCount = RoyaltyLine::query()->where('royalty_period_id', $this->period->id)->where('match_status', 'unmatched')->count();
        $unmatchedAmount = Decimal::sum(RoyaltyLine::query()->where('royalty_period_id', $this->period->id)->where('match_status', 'unmatched')->pluck('net_amount_base')->map(fn ($v) => (string) $v));

        $data = [
            'totals' => $totals,
            'unmatchedCount' => $unmatchedCount,
            'unmatchedAmount' => $unmatchedAmount,
            'currency' => config('labeldesk.currency'),
            'canManage' => auth()->user()->can('royalties.manage'),
            'hasEnded' => $this->period->ends_on->copy()->startOfDay()->lt(now()->setTimezone(config('labeldesk.display_timezone'))->startOfDay()),
        ];

        if ($this->tab === 'earnings') {
            $byArtist = [];
            foreach (RoyaltyEarning::query()->where('royalty_period_id', $this->period->id)->select(['artist_id', 'units', 'revenue', 'artist_amount', 'label_amount'])->cursor() as $e) {
                $row = $byArtist[$e->artist_id] ??= ['units' => 0, 'revenue' => '0', 'artist' => '0', 'label' => '0'];
                $row['units'] += $e->units;
                $row['revenue'] = Decimal::add($row['revenue'], (string) $e->revenue);
                $row['artist'] = Decimal::add($row['artist'], (string) $e->artist_amount);
                $row['label'] = Decimal::add($row['label'], (string) $e->label_amount);
                $byArtist[$e->artist_id] = $row;
            }
            $artists = Artist::withTrashed()->whereIn('id', array_keys($byArtist))->get()->keyBy('id');
            $data['earnings'] = collect($byArtist)
                ->map(fn ($row, $id) => $row + ['model' => $artists[$id] ?? null])
                ->sortByDesc(fn ($row) => (float) $row['revenue'])
                ->values();
        }

        return view('livewire.royalties.period-show', $data)
            ->title($this->period->name)
            ->layoutData(['breadcrumbs' => [
                ['label' => __('Business')],
                ['label' => __('Royalties'), 'url' => route('royalties.index')],
                ['label' => $this->period->name],
            ]]);
    }
}

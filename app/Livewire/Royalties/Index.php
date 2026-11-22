<?php

namespace App\Livewire\Royalties;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Services\Activity\ActivityLogger;
use App\Services\Royalties\RoyaltyCalculator;
use App\Services\Royalties\RoyaltyPeriodService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * Business → Royalties: accounting periods and royalty settings.
 */
class Index extends Component
{
    use EnforcesLicense;

    public bool $creating = false;

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public string $notes = '';

    public string $defaultShare = '';

    public function mount(RoyaltyCalculator $calculator): void
    {
        abort_unless(auth()->user()->can('royalties.view'), 403);
        $this->defaultShare = rtrim(rtrim($calculator->defaultShare(), '0'), '.') ?: '0';
    }

    protected function ensureManage(): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('royalties.manage'), 403);
    }

    public function startCreating(): void
    {
        $this->ensureManage();

        // Suggest the quarter after the latest period (or the current quarter).
        $last = RoyaltyPeriod::query()->orderByDesc('ends_on')->first();
        $start = $last ? $last->ends_on->copy()->addDay() : now()->setTimezone(config('labeldesk.display_timezone'))->firstOfQuarter();
        $end = $start->copy()->lastOfQuarter();

        $this->fill([
            'creating' => true,
            'name' => $start->year.' Q'.$start->quarter,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'notes' => '',
        ]);
        $this->resetValidation();
    }

    public function create(RoyaltyPeriodService $periods)
    {
        $this->ensureManage();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', 'unique:royalty_periods,name'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['starts_on' => __('start date'), 'ends_on' => __('end date')]);

        try {
            $period = $periods->create(auth()->user(), $data);
        } catch (RuntimeException $e) {
            $this->addError('starts_on', $e->getMessage());

            return null;
        }

        session()->flash('success', __('Period :name created. Upload its sales statements next.', ['name' => $period->name]));

        return $this->redirectRoute('royalties.periods.show', $period);
    }

    public function delete(int $id, RoyaltyPeriodService $periods): void
    {
        $this->ensureManage();

        try {
            $periods->delete(auth()->user(), RoyaltyPeriod::query()->findOrFail($id));
            $this->dispatch('toast', message: __('Period deleted.'), type: 'success');
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        }
    }

    public function saveDefaultShare(SettingsService $settings, ActivityLogger $activity, RoyaltyCalculator $calculator): void
    {
        $this->ensureManage();

        $this->defaultShare = str_replace(['%', ' ', ','], ['', '', '.'], $this->defaultShare);
        $this->validate(['defaultShare' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/', function ($a, $v, $fail) {
            if (bccomp((string) $v, '100', 4) === 1) {
                $fail(__('The share cannot be more than 100%.'));
            }
        }]], ['defaultShare.regex' => __('Enter a percentage such as 50 or 62.5.')]);

        $old = $calculator->defaultShare();
        $settings->set(RoyaltyCalculator::DEFAULT_SHARE_SETTING, $this->defaultShare, 'string');
        $activity->log('settings.updated', __('Changed the default artist share'), null, ['royalties.default_artist_share' => [$old, $this->defaultShare]]);

        $this->dispatch('toast', message: __('Default artist share saved. Recalculate open periods to apply it.'), type: 'success');
    }

    public function render(): View
    {
        $periods = RoyaltyPeriod::query()
            ->withCount(['imports as active_imports_count' => fn ($q) => $q->whereNotIn('status', ['reverted', 'failed'])])
            ->withSum('earnings as revenue_total', 'revenue')
            ->withSum('earnings as artist_total', 'artist_amount')
            ->orderByDesc('starts_on')
            ->get();

        $unmatched = RoyaltyLine::query()->where('match_status', 'unmatched')
            ->selectRaw('royalty_period_id, count(*) as total')->groupBy('royalty_period_id')->pluck('total', 'royalty_period_id');

        return view('livewire.royalties.index', [
            'periods' => $periods,
            'unmatched' => $unmatched,
            'canManage' => auth()->user()->can('royalties.manage'),
            'currency' => config('labeldesk.currency'),
        ])->title(__('Royalties'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Royalties')],
        ]]);
    }
}

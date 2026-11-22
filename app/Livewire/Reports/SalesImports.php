<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\RoyaltyImport;
use App\Models\RoyaltyPeriod;
use App\Services\Royalties\RoyaltyPeriodService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Reports → Import sales reports: upload store / distributor sales and
 * streaming reports (CSV, XLSX) into a royalty period. Mapping, matching
 * and processing are the royalty statement import (Royalties\Imports),
 * so imported sales feed the revenue reports, artist earnings and statements.
 */
class SalesImports extends Component
{
    use EnforcesLicense;

    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    public bool $creating = false;

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public function mount(): void
    {
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('royalties.import'), 403);

        if ($this->periodId === '' || ! RoyaltyPeriod::query()->whereKey((int) $this->periodId)->where('status', 'open')->exists()) {
            $this->periodId = (string) (RoyaltyPeriod::query()->where('status', 'open')->orderByDesc('ends_on')->value('id') ?? '');
        }
    }

    public function startCreating(): void
    {
        abort_unless(auth()->user()->can('royalties.manage'), 403);

        $last = RoyaltyPeriod::query()->orderByDesc('ends_on')->first();
        $start = $last ? $last->ends_on->copy()->addDay() : now()->setTimezone(config('labeldesk.display_timezone'))->firstOfMonth();
        $end = $start->copy()->lastOfMonth();

        $this->fill(['creating' => true, 'name' => $start->format('Y F'), 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()]);
        $this->resetValidation();
    }

    public function createPeriod(RoyaltyPeriodService $periods): void
    {
        abort_unless(auth()->user()->can('royalties.manage'), 403);
        $this->ensureLicenseAllowsWrites();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', 'unique:royalty_periods,name'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [], ['starts_on' => __('start date'), 'ends_on' => __('end date')]);

        try {
            $period = $periods->create(auth()->user(), $data + ['notes' => null]);
        } catch (RuntimeException $e) {
            $this->addError('starts_on', $e->getMessage());

            return;
        }

        $this->creating = false;
        $this->periodId = (string) $period->id;
        $this->dispatch('toast', type: 'success', message: __('Period :name created. Upload its sales reports below.', ['name' => $period->name]));
    }

    public function render(): View
    {
        $period = $this->periodId !== '' ? RoyaltyPeriod::query()->find((int) $this->periodId) : null;

        return view('livewire.reports.sales-imports', [
            'periods' => RoyaltyPeriod::query()->where('status', 'open')->orderByDesc('ends_on')->get(['id', 'name', 'starts_on', 'ends_on']),
            'period' => $period?->status === 'open' ? $period : null,
            'history' => RoyaltyImport::query()->with(['period:id,name', 'creator:id,name'])->latest('id')->limit(15)->get(),
            'currency' => config('labeldesk.currency'),
            'canManage' => auth()->user()->can('royalties.manage'),
        ])->title(__('Import sales reports'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Reports'), 'url' => route('reports.index')], ['label' => __('Import sales reports')],
        ]]);
    }
}

<?php

namespace App\Livewire\Releases;

use App\Enums\DeliveryStatus;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Dsp;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use App\Services\Distribution\DistributionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Release page → Distribution tab: stores, delivery status and takedowns.
 */
class Distribution extends Component
{
    use EnforcesLicense;

    #[Locked]
    public Release $release;

    public bool $adding = false;

    /** @var list<int|string> */
    public array $selectedDsps = [];

    /** Inline action panel: a delivery id (or "all") + action. */
    #[Locked]
    public int|string|null $panelFor = null;

    #[Locked]
    public ?string $panelAction = null;

    public string $date = '';

    public string $comment = '';

    public string $store_id = '';

    public string $store_url = '';

    public string $notes = '';

    public ?int $expanded = null;

    public function mount(Release $release): void
    {
        $this->ensureFeature('distribution');
        abort_unless(auth()->user()->can('distribution.view'), 403);
        $this->release = $release;
    }

    #[On('release-updated')]
    public function refreshRelease(): void
    {
        $this->release->refresh();
    }

    protected function ensureCanManage(): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('distribution');
        abort_unless(auth()->user()->can('distribution.manage'), 403);
    }

    public function startAdding(): void
    {
        $this->ensureCanManage();
        $this->adding = true;
        $this->selectedDsps = [];
    }

    public function selectAll(): void
    {
        $this->selectedDsps = $this->availableDsps()->keys()->map(fn ($id) => (string) $id)->all();
    }

    public function add(DistributionService $distribution): void
    {
        $this->ensureCanManage();
        $this->validate(['selectedDsps' => ['required', 'array', 'min:1'], 'selectedDsps.*' => ['integer']], [
            'selectedDsps.required' => __('Choose at least one store.'),
        ]);

        try {
            $count = $distribution->addDeliveries(auth()->user(), $this->release, array_map('intval', $this->selectedDsps));
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->reset('adding', 'selectedDsps');
        $this->dispatch('toast', message: trans_choice('{0} No new stores were added.|{1} 1 store added.|[2,*] :count stores added.', $count, ['count' => $count]), type: 'success');
        $this->dispatch('release-updated');
    }

    public function openPanel(int|string $for, string $action): void
    {
        $this->ensureCanManage();

        if ($for !== 'all') {
            $delivery = $this->find((int) $for);
            if ($action !== 'details' && ! in_array($action, app(DistributionService::class)->available($delivery), true)) {
                abort(403);
            }
            $this->store_id = (string) $delivery->store_id;
            $this->store_url = (string) $delivery->store_url;
            $this->notes = (string) $delivery->notes;
        }

        $this->panelFor = $for;
        $this->panelAction = $action;
        $this->date = now()->setTimezone(config('labeldesk.display_timezone'))->format('Y-m-d');
        $this->comment = '';
        $this->resetValidation();
    }

    public function closePanel(): void
    {
        $this->reset('panelFor', 'panelAction', 'date', 'comment', 'store_id', 'store_url', 'notes');
        $this->resetValidation();
    }

    public function submitPanel(DistributionService $distribution): void
    {
        $this->ensureCanManage();
        $action = (string) $this->panelAction;

        $rules = [
            'store_id' => ['nullable', 'string', 'max:100'],
            'store_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
        if ($action !== 'details') {
            if ($distribution->setsDate($action)) {
                $rules['date'] = ['required', 'date_format:Y-m-d']; // future dates are refused in the label's timezone by the service
            }
            if ($distribution->requiresComment($action)) {
                $rules['comment'] = ['required', 'string', 'max:2000'];
            }
        }
        $this->validate($rules, [
            'store_url.url' => __('Enter a full web address starting with https://'),
            'date.before_or_equal' => __('The date cannot be in the future.'),
        ], ['comment' => __('reason'), 'store_url' => __('store link')]);

        try {
            if ($this->panelFor === 'all') {
                $count = $distribution->transitionAll(auth()->user(), $this->release, $action, $this->comment ?: null, $this->date ?: null);
                $message = trans_choice('{0} No stores were updated.|{1} 1 store updated.|[2,*] :count stores updated.', $count, ['count' => $count]);
            } elseif ($action === 'details') {
                $distribution->updateDetails(auth()->user(), $this->find((int) $this->panelFor), $this->store_id, $this->store_url, $this->notes);
                $message = __('Store details saved.');
            } else {
                $delivery = $distribution->transition(auth()->user(), $this->find((int) $this->panelFor), $action, $this->comment ?: null, $this->date ?: null, [
                    'store_id' => $this->store_id, 'store_url' => $this->store_url,
                ]);
                $message = __(':store is now :status.', ['store' => $delivery->dsp->name, 'status' => strtolower($delivery->status->label())]);
            }
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->closePanel();
        $this->dispatch('toast', message: $message, type: 'success');
        $this->dispatch('release-updated');
    }

    public function remove(int $id, DistributionService $distribution): void
    {
        $this->ensureCanManage();

        try {
            $distribution->remove(auth()->user(), $this->find($id));
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', message: __('Store removed.'), type: 'success');
        $this->dispatch('release-updated');
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    protected function find(int $id): ReleaseDelivery
    {
        return ReleaseDelivery::query()->with(['dsp', 'release'])->where('release_id', $this->release->id)->find($id) ?? abort(404);
    }

    /**
     * @return Collection<int, string>
     */
    protected function availableDsps()
    {
        return Dsp::query()->where('is_active', true)
            ->whereNotIn('id', $this->release->deliveries()->pluck('dsp_id'))
            ->orderBy('name')->pluck('name', 'id');
    }

    public function render(DistributionService $distribution): View
    {
        $deliveries = $this->release->deliveries()
            ->with(['dsp', 'release:id,release_date,deleted_at', 'events' => fn ($q) => $q->with('user:id,name,deleted_at')])
            ->get()
            ->sortBy(fn (ReleaseDelivery $d) => $d->dsp->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $bulk = [];
        foreach (['deliver', 'go_live'] as $action) {
            $n = $deliveries->filter(fn ($d) => in_array($action, $distribution->available($d), true))->count();
            if ($n > 1) {
                $bulk[$action] = $n;
            }
        }

        return view('livewire.releases.distribution', [
            'deliveries' => $deliveries,
            'counts' => $deliveries->countBy(fn ($d) => $d->status->value),
            'statuses' => DeliveryStatus::cases(),
            'canManage' => auth()->user()->can('distribution.manage'),
            'canDistribute' => $distribution->canDistribute($this->release),
            'availableDsps' => $this->adding ? $this->availableDsps() : collect(),
            'labels' => DistributionService::labels(),
            'service' => $distribution,
            'bulk' => $bulk,
        ]);
    }
}

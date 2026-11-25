<?php

namespace LabelDesk\Addons\PdlDistribution\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Release;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\PdlDistribution\Models\PdlDelivery;
use LabelDesk\Addons\PdlDistribution\Services\Cosmos;
use LabelDesk\Addons\PdlDistribution\Services\CosmosException;
use LabelDesk\Addons\PdlDistribution\Services\DeliveryException;
use LabelDesk\Addons\PdlDistribution\Services\DeliveryService;
use LabelDesk\Addons\PdlDistribution\Services\PayloadBuilder;
use LabelDesk\Addons\PdlDistribution\Services\PdlSettings;
use Livewire\Component;

/**
 * One release at PDL: what is missing before it can be sent, "Send to PDL" /
 * "Send update", progress, PDL's review and release status and history.
 */
class ReleasePage extends Component
{
    use EnforcesLicense;

    public Release $release;

    public string $platforms = 'AllPlatformWithYouTube';

    public bool $allow_any_go_live_date = false;

    /** Fallback mood for tracks without one (LabelDesk 4.3.6 tracks have their own mood). */
    public string $mood = '';

    /** @var list<string> problems from the last send attempt */
    public array $problems = [];

    public function mount(Release $release, PdlSettings $settings): void
    {
        abort_unless(auth()->user()?->can('pdl.view'), 403);
        $this->release = $release;

        $delivery = $this->delivery();
        $this->platforms = $delivery?->platforms ?? (string) $settings->get('platforms');
        $this->allow_any_go_live_date = $delivery?->allow_any_go_live_date ?? (bool) $settings->get('allow_any_go_live_date');
        $this->mood = $delivery?->mood ?? (string) $settings->get('default_mood');
    }

    protected function delivery(): ?PdlDelivery
    {
        return PdlDelivery::query()->where('release_id', $this->release->id)->first();
    }

    protected function authorizeDeliver(): void
    {
        abort_unless(auth()->user()?->can('pdl.deliver'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    public function send(DeliveryService $deliveries, PayloadBuilder $builder): void
    {
        $this->authorizeDeliver();
        $this->validate([
            'platforms' => ['required', Rule::in(array_keys(Cosmos::PLATFORMS))],
            'allow_any_go_live_date' => ['boolean'],
            'mood' => [$builder->needsFallbackMood($this->release->fresh()) ? 'required' : 'nullable', Rule::in(Cosmos::MOODS)],
        ], ['mood.required' => __('Some tracks have no mood: choose one for them.')], ['platforms' => __('release on'), 'mood' => __('mood')]);

        try {
            $delivery = $deliveries->queue($this->release->fresh(), auth()->user(), [
                'platforms' => $this->platforms, 'allow_any_go_live_date' => $this->allow_any_go_live_date, 'mood' => $this->mood,
            ]);
        } catch (DeliveryException $e) {
            $this->problems = $e->problems;
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());

            return;
        }

        $this->problems = [];
        $this->dispatch('toast', type: 'success', message: $delivery->action === 'update'
            ? __('Update queued. It is sent to PDL in the background.')
            : __('Queued. Metadata, artwork and audio are sent to PDL in the background.'));
    }

    public function retry(DeliveryService $deliveries): void
    {
        $this->authorizeDeliver();
        $delivery = $this->delivery() ?? abort(404);

        try {
            $deliveries->retry($delivery, auth()->user());
            $this->dispatch('toast', type: 'success', message: __('Trying again in the background.'));
        } catch (DeliveryException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function checkStatus(DeliveryService $deliveries): void
    {
        abort_unless(auth()->user()?->can('pdl.deliver'), 403);
        $delivery = $this->delivery() ?? abort(404);
        if (! $delivery->pdl_album_id) {
            return;
        }

        try {
            $deliveries->syncStatus($delivery);
            $this->dispatch('toast', type: 'success', message: __('PDL status: :status.', ['status' => $delivery->fresh()->statusLabel()]));
        } catch (CosmosException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function render(PayloadBuilder $builder, PdlSettings $settings): View
    {
        $delivery = $this->delivery()?->load(['events' => fn ($q) => $q->with('user:id,name')->limit(50), 'requester:id,name']);
        $needsMood = $builder->needsFallbackMood($this->release);
        // Missing moods are answered by the fallback field below, so they are not listed as problems here.
        $check = $builder->check($this->release, $needsMood ? 'Happy' : null);
        $problems = $check['problems'];

        $canSend = $delivery === null
            || (! $delivery->isInProgress() && (! $delivery->isSubmitted() || in_array($delivery->status, ['issue_reported', 'failed'], true)));

        return view('pdl-distribution::release', [
            'delivery' => $delivery,
            'check' => ['problems' => $problems, 'warnings' => $check['warnings']],
            'configured' => $settings->isEnabled(),
            'needsMood' => $needsMood,
            'canSend' => $canSend,
            'isUpdate' => $delivery?->pdl_album_id !== null,
            'uploads' => collect((array) data_get($delivery?->ingest, 'uploads', []))->map(fn ($u) => ['filename' => $u['filename'] ?? '', 'size' => (int) ($u['size'] ?? 0), 'done' => (bool) ($u['done'] ?? false)])->values(),
        ])->title(__('PDL: :title', ['title' => $this->release->displayTitle()]))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('PDL distribution'), 'url' => route('pdl.index')], ['label' => $this->release->displayTitle()],
        ]]);
    }
}

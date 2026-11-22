<?php

namespace LabelDesk\Addons\IncomingWebhooks\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use Illuminate\Contracts\View\View;
use LabelDesk\Addons\IncomingWebhooks\Actions\WebhookActionException;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;
use LabelDesk\Addons\IncomingWebhooks\Services\WebhookProcessor;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Administration → Webhooks → Delivery log: every request received, with
 * its (redacted) headers, payload, result and error, and replay.
 */
class Deliveries extends Component
{
    use EnforcesLicense, WithPagination;

    #[Url]
    public string $endpoint = '';

    #[Url]
    public string $status = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['endpoint', 'status'], true)) {
            $this->resetPage();
            $this->expanded = null;
        }
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset('endpoint', 'status', 'expanded');
        $this->resetPage();
    }

    public function replay(int $id, WebhookProcessor $processor): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorizeManage();

        $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($id);
        try {
            $copy = $processor->replay($delivery, auth()->user());
        } catch (WebhookActionException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->expanded = $copy->id;
        $this->dispatch('toast', message: $copy->status === WebhookDelivery::PROCESSED
            ? __('Replayed: processed.')
            : __('Replayed: :status. :error', ['status' => $copy->statusLabel(), 'error' => (string) $copy->error]), type: $copy->status === WebhookDelivery::PROCESSED ? 'success' : 'warning');
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('webhooks.manage'), 403);
    }

    public function render(): View
    {
        $deliveries = WebhookDelivery::query()->with('endpoint:id,name,action', 'replayer:id,name')
            ->when($this->endpoint !== '', fn ($q) => $q->where('webhook_endpoint_id', (int) $this->endpoint))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(25);

        return view('incoming-webhooks::livewire.deliveries', [
            'deliveries' => $deliveries,
            'endpoints' => WebhookEndpoint::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => WebhookDelivery::statusLabels(),
            'retentionDays' => WebhookDelivery::RETENTION_DAYS,
        ])->title(__('Webhook delivery log'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')], ['label' => __('Webhooks'), 'url' => route('incoming-webhooks.index')], ['label' => __('Delivery log')],
        ]]);
    }
}

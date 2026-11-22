<?php

namespace LabelDesk\Addons\IncomingWebhooks\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Dsp;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use LabelDesk\Addons\IncomingWebhooks\Actions\ActionRegistry;
use LabelDesk\Addons\IncomingWebhooks\Actions\DeliveryStatusAction;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;
use LabelDesk\Addons\IncomingWebhooks\Services\EndpointService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Administration → Webhooks: incoming webhook endpoints.
 */
class Endpoints extends Component
{
    use EnforcesLicense;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $action = 'delivery_status';

    public int|string $acting_user_id = '';

    public int|string $default_dsp_id = '';

    public int|string $tolerance_seconds = 300;

    public string $allowed_ips = '';

    public bool $is_active = true;

    /** @var array<string, string> payload field paths (empty = default) */
    public array $fields = ['event_id' => '', 'upc' => '', 'isrc' => '', 'store' => '', 'status' => '', 'date' => '', 'store_id' => '', 'store_url' => '', 'reason' => ''];

    /** Secret shown once after create/rotate. */
    #[Locked]
    public ?string $revealedSecret = null;

    #[Locked]
    public ?int $revealedFor = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->acting_user_id = $this->actingUsers()->contains('id', auth()->id()) ? (string) auth()->id() : '';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $endpoint->id;
        $this->fill([
            'name' => $endpoint->name,
            'action' => $endpoint->action,
            'acting_user_id' => (string) $endpoint->acting_user_id,
            'default_dsp_id' => (string) $endpoint->setting('default_dsp_id', ''),
            'tolerance_seconds' => (string) $endpoint->tolerance_seconds,
            'allowed_ips' => implode("\n", (array) $endpoint->allowed_ips),
            'is_active' => $endpoint->is_active,
        ]);
        foreach (array_keys($this->fields) as $field) {
            $this->fields[$field] = (string) $endpoint->setting('fields.'.$field, '');
        }
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(EndpointService $service, ActionRegistry $actions): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorizeManage();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'action' => ['required', Rule::in(array_keys($actions->options()))],
            'acting_user_id' => [Rule::requiredIf($this->action === 'delivery_status'), 'nullable', Rule::in($this->actingUsers()->pluck('id')->all())],
            'default_dsp_id' => ['nullable', Rule::exists('dsps', 'id')],
            'tolerance_seconds' => ['required', 'integer', 'between:60,3600'],
            'allowed_ips' => ['nullable', 'string', 'max:5000', function ($attribute, $value, $fail) {
                foreach ($this->ipList((string) $value) as $entry) {
                    if (! $this->validIpEntry($entry)) {
                        $fail(__('":entry" is not an IP address or range (e.g. 203.0.113.10 or 203.0.113.0/24).', ['entry' => $entry]));

                        return;
                    }
                }
            }],
            'fields.*' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/'],
            'is_active' => ['boolean'],
        ], [
            'acting_user_id.in' => __('Choose an active user who may manage deliveries.'),
            'fields.*.regex' => __('Use a field name or a dotted path such as data.status.'),
        ], [
            'acting_user_id' => __('acts as'),
            'tolerance_seconds' => __('time window'),
            'allowed_ips' => __('IP allow-list'),
        ]);

        $data = [
            'name' => trim($this->name),
            'action' => $this->action,
            'acting_user_id' => $this->acting_user_id !== '' ? (int) $this->acting_user_id : null,
            'tolerance_seconds' => (int) $this->tolerance_seconds,
            'allowed_ips' => $this->ipList($this->allowed_ips) ?: null,
            'is_active' => $this->is_active,
            'settings' => array_filter([
                'default_dsp_id' => $this->default_dsp_id !== '' ? (int) $this->default_dsp_id : null,
                'fields' => array_filter(array_map(fn ($v) => trim((string) $v), $this->fields)) ?: null,
            ]) ?: null,
        ];

        if ($this->editingId) {
            $service->update(auth()->user(), WebhookEndpoint::query()->findOrFail($this->editingId), $data);
            $this->dispatch('toast', message: __('Endpoint updated.'), type: 'success');
        } else {
            [$endpoint, $secret] = $service->create(auth()->user(), $data);
            $this->revealedSecret = $secret;
            $this->revealedFor = $endpoint->id;
        }

        $this->resetForm();
    }

    public function toggleActive(int $id, EndpointService $service): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorizeManage();
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        $service->setActive(auth()->user(), $endpoint, ! $endpoint->is_active);
    }

    public function rotate(int $id, bool $immediately = false): void
    {
        $service = app(EndpointService::class);
        $this->ensureLicenseAllowsWrites();
        $this->authorizeManage();
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        $this->revealedSecret = $service->rotate(auth()->user(), $endpoint, $immediately);
        $this->revealedFor = $endpoint->id;
    }

    public function revokePrevious(int $id, EndpointService $service): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorizeManage();
        $service->revokePreviousSecret(auth()->user(), WebhookEndpoint::query()->findOrFail($id));
        $this->dispatch('toast', message: __('The old secret no longer works.'), type: 'success');
    }

    public function delete(int $id, EndpointService $service): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->authorizeManage();
        $service->delete(auth()->user(), WebhookEndpoint::query()->findOrFail($id));
        if ($this->revealedFor === $id) {
            $this->dismissSecret();
        }
        $this->dispatch('toast', message: __('Endpoint deleted.'), type: 'success');
    }

    public function dismissSecret(): void
    {
        $this->revealedSecret = null;
        $this->revealedFor = null;
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('webhooks.manage'), 403);
    }

    /** Active users who may manage deliveries (an endpoint "acts as" one of them). */
    protected function actingUsers(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->can('distribution.manage'))->values();
    }

    /** @return list<string> */
    protected function ipList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: []))));
    }

    protected function validIpEntry(string $entry): bool
    {
        [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'action', 'acting_user_id', 'default_dsp_id', 'tolerance_seconds', 'allowed_ips', 'is_active');
        $this->fields = ['event_id' => ''] + array_fill_keys(array_keys(DeliveryStatusAction::FIELDS), '');
        $this->resetValidation();
    }

    public function render(ActionRegistry $actions): View
    {
        $endpoints = WebhookEndpoint::query()->with('actingUser')
            ->withCount([
                'deliveries as recent_count' => fn ($q) => $q->where('created_at', '>=', now()->subDay()),
                'deliveries as recent_failed_count' => fn ($q) => $q->where('created_at', '>=', now()->subDay())->whereIn('status', [WebhookDelivery::FAILED, WebhookDelivery::REJECTED]),
            ])
            ->orderBy('name')->get();

        $actionList = [];
        foreach (array_keys($actions->options()) as $key) {
            $actionList[$key] = $actions->get($key);
        }

        return view('incoming-webhooks::livewire.endpoints', [
            'endpoints' => $endpoints,
            'actions' => $actionList,
            'users' => $this->showForm ? $this->actingUsers() : collect(),
            'dsps' => $this->showForm ? Dsp::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'defaults' => ['event_id' => 'id'] + DeliveryStatusAction::FIELDS,
            'graceHours' => WebhookEndpoint::ROTATION_GRACE_HOURS,
        ])->title(__('Webhooks'))->layoutData(['breadcrumbs' => [
            ['label' => __('Administration')], ['label' => __('Webhooks')],
        ]]);
    }
}

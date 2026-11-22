<div>
    <x-page-header :title="__('Webhook delivery log')" :subtitle="__('Every request your endpoints received, newest first. Kept for :days days; secret headers are not stored.', ['days' => $retentionDays])">
        <x-slot:actions>
            <a href="{{ route('incoming-webhooks.index') }}" class="btn btn-light" wire:navigate><x-icon name="webhook" size="16" /> {{ __('Endpoints') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4 col-xl-3">
                    <select class="form-select" wire:model.live="endpoint" aria-label="{{ __('Filter by endpoint') }}">
                        <option value="">{{ __('All endpoints') }}</option>
                        @foreach ($endpoints as $e)
                            <option value="{{ $e->id }}">{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-4 col-xl-2">
                    <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 col-xl-1">
                    @if ($endpoint !== '' || $status !== '')
                        <button type="button" class="btn btn-light w-100" wire:click="clearFilters">{{ __('Clear') }}</button>
                    @endif
                </div>
            </div>
        </div>

        @if ($deliveries->isEmpty())
            <x-empty-state icon="history" :title="__('No deliveries')" :description="$endpoint !== '' || $status !== '' ? __('Nothing matches these filters.') : __('Requests to your webhook endpoints will appear here.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Received') }}</th>
                            <th>{{ __('Endpoint') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Event id') }}</th>
                            <th>{{ __('Result') }}</th>
                            <th><span class="visually-hidden">{{ __('Details') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->id }}">
                                <td class="text-nowrap small">
                                    {{ App\Support\Format::datetime($delivery->created_at) }}
                                    @if ($delivery->replay_of_id)
                                        <div class="text-muted">{{ __('Replay of #:id', ['id' => $delivery->replay_of_id]) }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $delivery->endpoint?->name }}</td>
                                <td>
                                    <x-badge :variant="$delivery->statusVariant()">{{ $delivery->statusLabel() }}</x-badge>
                                    @if ($delivery->http_status) <span class="small text-muted ms-1">{{ $delivery->http_status }}</span> @endif
                                </td>
                                <td class="d-none d-md-table-cell small font-monospace text-truncate" style="max-width: 14rem">{{ $delivery->event_id ?? '—' }}</td>
                                <td class="small">
                                    @if ($delivery->error)
                                        <span class="text-danger">{{ Str::limit($delivery->error, 120) }}</span>
                                    @elseif (($delivery->result['outcome'] ?? null) === 'updated')
                                        {{ __(':release at :store: :from → :to', ['release' => $delivery->result['release'] ?? '', 'store' => $delivery->result['store'] ?? '', 'from' => App\Enums\DeliveryStatus::tryFrom($delivery->result['from'] ?? '')?->label() ?? '', 'to' => App\Enums\DeliveryStatus::tryFrom($delivery->result['to'] ?? '')?->label() ?? '']) }}
                                    @elseif (($delivery->result['outcome'] ?? null) === 'unchanged')
                                        {{ __(':release at :store: already :status', ['release' => $delivery->result['release'] ?? '', 'store' => $delivery->result['store'] ?? '', 'status' => App\Enums\DeliveryStatus::tryFrom($delivery->result['to'] ?? '')?->label() ?? '']) }}
                                    @elseif (($delivery->result['outcome'] ?? null) === 'logged')
                                        {{ __('Logged') }}
                                    @elseif (($delivery->result['outcome'] ?? null) === 'duplicate')
                                        {{ __('Already received; not applied again') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="ld-icon-btn" wire:click="toggle({{ $delivery->id }})"
                                            aria-expanded="{{ $expanded === $delivery->id ? 'true' : 'false' }}" aria-label="{{ __('Show details') }}">
                                        <x-icon :name="$expanded === $delivery->id ? 'chevron-up' : 'chevron-down'" size="16" />
                                    </button>
                                </td>
                            </tr>
                            @if ($expanded === $delivery->id)
                                <tr wire:key="delivery-{{ $delivery->id }}-details">
                                    <td colspan="6" class="small" style="background: var(--ld-surface-hover)">
                                        <div class="row g-3">
                                            <div class="col-lg-5">
                                                <dl class="ld-dl mb-2">
                                                    <dt>{{ __('Delivery') }}</dt><dd>#{{ $delivery->id }}</dd>
                                                    <dt>{{ __('Signature') }}</dt><dd>{{ $delivery->signature_valid ? __('Valid') : __('Not verified') }}</dd>
                                                    <dt>{{ __('IP address') }}</dt><dd class="font-monospace">{{ $delivery->ip_address ?? ($delivery->replay_of_id ? __('Replay by :name', ['name' => $delivery->replayer?->name ?? '—']) : '—') }}</dd>
                                                    <dt>{{ __('Size') }}</dt><dd>{{ App\Support\Format::bytes($delivery->payload_size) }} @if ($delivery->payload_truncated) <span class="text-warning">({{ __('truncated in the log') }})</span> @endif</dd>
                                                    @if ($delivery->attempts > 1) <dt>{{ __('Attempts') }}</dt><dd>{{ $delivery->attempts }}</dd> @endif
                                                    @if ($delivery->processed_at) <dt>{{ __('Processed') }}</dt><dd>{{ App\Support\Format::datetime($delivery->processed_at) }}</dd> @endif
                                                    @if ($delivery->error) <dt>{{ __('Error') }}</dt><dd class="text-danger text-break">{{ $delivery->error }}</dd> @endif
                                                    @if ($delivery->result)
                                                        <dt>{{ __('Result') }}</dt><dd class="text-break font-monospace">{{ json_encode($delivery->result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd>
                                                    @endif
                                                </dl>
                                                @if ($delivery->headers)
                                                    <div class="fw-semibold mb-1">{{ __('Headers') }}</div>
                                                    <pre class="p-2 rounded border mb-0" style="max-height: 16rem; overflow: auto"><code>@foreach ($delivery->headers as $name => $value){{ $name }}: {{ $value }}
@endforeach</code></pre>
                                                @endif
                                            </div>
                                            <div class="col-lg-7">
                                                <div class="fw-semibold mb-1">{{ __('Payload') }}</div>
                                                @php($decoded = $delivery->decodedPayload())
                                                <pre class="p-2 rounded border mb-2" style="max-height: 24rem; overflow: auto"><code>{{ $decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($delivery->payload ?? '') }}</code></pre>
                                                @if ($delivery->canReplay())
                                                    <button type="button" class="btn btn-sm btn-light" wire:click="replay({{ $delivery->id }})"
                                                            wire:confirm="{{ __('Process this payload again now? Its changes are applied again if the data has changed since.') }}">
                                                        <x-icon name="rotate-ccw" size="14" /> {{ __('Replay') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($deliveries->hasPages())
                <div class="card-body border-top py-2">{{ $deliveries->links() }}</div>
            @endif
        @endif
    </div>
</div>

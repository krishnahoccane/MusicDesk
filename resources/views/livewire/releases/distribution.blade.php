<div>
    @if (! $canDistribute && $deliveries->isEmpty())
        <x-alert variant="info" class="mb-3">
            {{ __('A release can be sent to stores once it is approved and marked ready for delivery.') }}
        </x-alert>
    @endif

    @if ($adding)
        <form wire:submit="add" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="send" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Add stores') }}</h2>
                @if ($availableDsps->isNotEmpty())
                    <button type="button" class="btn btn-sm btn-link ms-auto p-0" wire:click="selectAll">{{ __('Select all') }}</button>
                @endif
            </div>
            <div class="card-body">
                @if ($availableDsps->isEmpty())
                    <p class="text-muted small mb-0">{{ __('Every active store has already been added.') }}
                        @can('dsps.manage') <a href="{{ route('distribution.dsps') }}">{{ __('Manage stores') }}</a> @endcan
                    </p>
                @else
                    <div class="row g-1">
                        @foreach ($availableDsps as $id => $name)
                            <div class="col-sm-6 col-lg-4" wire:key="pick-{{ $id }}">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="dsp-{{ $id }}" value="{{ $id }}" wire:model="selectedDsps">
                                    <label class="form-check-label" for="dsp-{{ $id }}">{{ $name }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @error('selectedDsps') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                @endif
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="$set('adding', false)">{{ __('Cancel') }}</button>
                @if ($availableDsps->isNotEmpty())
                    <button type="submit" class="btn btn-primary">{{ __('Add as pending') }}</button>
                @endif
            </div>
        </form>
    @endif

    @if ($panelFor === 'all' && $panelAction)
        @include('livewire.releases.partials.delivery-panel', ['title' => $labels[$panelAction].' — '.__('all eligible stores')])
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <x-icon name="send" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Stores') }}</h2>
            <div class="d-flex flex-wrap gap-1 ms-2">
                @foreach ($statuses as $status)
                    @if (($counts[$status->value] ?? 0) > 0)
                        <x-badge :variant="$status->variant()">{{ $counts[$status->value] }} {{ strtolower($status->label()) }}</x-badge>
                    @endif
                @endforeach
            </div>
            @if ($canManage)
                <div class="ms-auto d-flex gap-2">
                    @foreach ($bulk as $action => $n)
                        <button type="button" class="btn btn-sm btn-light" wire:click="openPanel('all', '{{ $action }}')">{{ $labels[$action] }} ({{ $n }})</button>
                    @endforeach
                    @if ($canDistribute && ! $adding)
                        <button type="button" class="btn btn-sm btn-primary" wire:click="startAdding"><x-icon name="plus" size="14" /> {{ __('Add stores') }}</button>
                    @endif
                </div>
            @endif
        </div>

        @if ($deliveries->isEmpty())
            <x-empty-state icon="send" :title="__('Not sent to any store yet')"
                           :description="__('Record each store you deliver this release to and follow it until it is live.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Store') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Delivered') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Live') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('Store link') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            @php($available = $service->available($delivery))
                            <tr wire:key="d-{{ $delivery->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $delivery->dsp->name }}</div>
                                    @if ($delivery->isLate())
                                        <div class="small text-warning"><x-icon name="triangle-alert" size="12" /> {{ __('Deliver at least :days days before release', ['days' => $delivery->dsp->lead_time_days]) }}</div>
                                    @elseif ($delivery->store_id)
                                        <div class="small text-muted font-monospace">{{ $delivery->store_id }}</div>
                                    @endif
                                </td>
                                <td><x-badge :variant="$delivery->status->variant()">{{ $delivery->status->label() }}</x-badge></td>
                                <td class="d-none d-md-table-cell small">{{ App\Support\Format::date($delivery->delivered_on) }}</td>
                                <td class="d-none d-md-table-cell small">{{ App\Support\Format::date($delivery->live_on) }}</td>
                                <td class="d-none d-lg-table-cell small">
                                    @if ($delivery->store_url)
                                        <a href="{{ $delivery->store_url }}" target="_blank" rel="noopener noreferrer nofollow">{{ __('Open') }} <x-icon name="external-link" size="12" /></a>
                                    @else — @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-light" wire:click="toggle({{ $delivery->id }})" aria-label="{{ __('History') }}"
                                            aria-expanded="{{ $expanded === $delivery->id ? 'true' : 'false' }}"><x-icon name="history" size="14" /></button>
                                    @if ($canManage)
                                        <div class="dropdown d-inline-block">
                                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Actions for :store', ['store' => $delivery->dsp->name]) }}">
                                                <x-icon name="ellipsis-vertical" size="14" />
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                @foreach ($available as $action)
                                                    <li><button type="button" class="dropdown-item" wire:click="openPanel({{ $delivery->id }}, '{{ $action }}')">{{ $labels[$action] }}</button></li>
                                                @endforeach
                                                <li><button type="button" class="dropdown-item" wire:click="openPanel({{ $delivery->id }}, 'details')">{{ __('Edit store details') }}</button></li>
                                                @if ($delivery->status === App\Enums\DeliveryStatus::Pending)
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><button type="button" class="dropdown-item text-danger" wire:click="remove({{ $delivery->id }})"
                                                                wire:confirm="{{ __('Remove :store from this release?', ['store' => $delivery->dsp->name]) }}">{{ __('Remove') }}</button></li>
                                                @endif
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            @if ($panelFor === $delivery->id && $panelAction)
                                <tr wire:key="d-{{ $delivery->id }}-panel">
                                    <td colspan="6" class="border-top-0 pt-0">
                                        @include('livewire.releases.partials.delivery-panel', ['title' => $panelAction === 'details' ? __('Store details — :store', ['store' => $delivery->dsp->name]) : $labels[$panelAction].' — '.$delivery->dsp->name])
                                    </td>
                                </tr>
                            @endif
                            @if ($expanded === $delivery->id)
                                <tr wire:key="d-{{ $delivery->id }}-history">
                                    <td colspan="6" class="small" style="background: var(--ld-surface-hover)">
                                        @if ($delivery->notes)
                                            <div class="mb-2"><span class="fw-semibold">{{ __('Notes') }}:</span> <span style="white-space: pre-line">{{ $delivery->notes }}</span></div>
                                        @endif
                                        <ul class="list-unstyled mb-0">
                                            @foreach ($delivery->events as $event)
                                                <li class="py-1">
                                                    <x-badge :variant="$event->to_status->variant()">{{ $event->to_status->label() }}</x-badge>
                                                    <span class="text-muted ms-1">{{ $event->user?->name ?? __('System') }} &middot; {{ App\Support\Format::datetime($event->created_at) }}</span>
                                                    @if ($event->comment) <div class="ms-1 mt-1" style="white-space: pre-line">{{ $event->comment }}</div> @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div>
    <x-page-header :title="__('Distribution')" :subtitle="__('Where each release has been delivered and its status at every store.')">
        @can('dsps.manage')
            <x-slot:actions>
                <a href="{{ route('distribution.dsps') }}" class="btn btn-light"><x-icon name="store" size="16" /> {{ __('Manage stores') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-3">
        @foreach ([['pending', 'clock', null], ['delivered', 'send', 'info'], ['live', 'radio', 'success'], ['takedown_requested', 'circle-pause', 'warning']] as [$key, $icon, $variant])
            @php($statusCase = App\Enums\DeliveryStatus::from($key))
            <div class="col-6 col-xl-3">
                <button type="button" class="card ld-stat text-reset text-start w-100 border-0" wire:click="$set('status', '{{ $key }}')">
                    <span @class(['ld-stat__icon', 'ld-stat__icon--'.$variant => $variant])><x-icon :name="$icon" size="20" /></span>
                    <div class="min-w-0">
                        <div class="ld-stat__label">{{ $statusCase->label() }}</div>
                        <div class="ld-stat__value">{{ number_format($counts[$key] ?? 0) }}</div>
                    </div>
                </button>
            </div>
        @endforeach
    </div>

    @if ($awaiting->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="package-check" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Ready but not sent to any store') }}</h2>
            </div>
            <ul class="list-unstyled mb-0">
                @foreach ($awaiting as $release)
                    <li class="d-flex align-items-center gap-3 px-4 py-2 border-bottom" wire:key="aw-{{ $release->id }}">
                        <x-release-artwork :release="$release" size="36" />
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('releases.show', ['release' => $release, 'tab' => 'distribution']) }}" class="fw-semibold">{{ $release->displayTitle() }}</a>
                            <div class="small text-muted">{{ $release->artistLine() }} @if ($release->release_date) &middot; {{ App\Support\Format::date($release->release_date) }} @endif</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-icon">
                        <x-icon name="search" size="16" />
                        <input type="search" class="form-control" placeholder="{{ __('Release, artist or UPC') }}"
                               wire:model.live.debounce.300ms="search" aria-label="{{ __('Search deliveries') }}" maxlength="100">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select" wire:model.live="dsp" aria-label="{{ __('Filter by store') }}">
                        <option value="">{{ __('All stores') }}</option>
                        @foreach ($dsps as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1 text-md-end">
                    @if ($filtered)
                        <button type="button" class="btn btn-light" wire:click="clearFilters" aria-label="{{ __('Clear filters') }}"><x-icon name="x" size="16" /></button>
                    @endif
                </div>
            </div>
        </div>

        @if ($deliveries->isEmpty())
            <x-empty-state icon="send" :title="$filtered ? __('Nothing found') : __('No deliveries yet')"
                           :description="$filtered ? __('Try a different search or filter.') : __('Open a release that is ready for delivery and add stores on its Distribution tab.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Release') }}</th>
                            <th>{{ __('Store') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Delivered') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Live') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr wire:key="del-{{ $delivery->id }}">
                                <td>
                                    <a href="{{ route('releases.show', ['release' => $delivery->release, 'tab' => 'distribution']) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                        <x-release-artwork :release="$delivery->release" size="32" />
                                        <span class="lh-sm min-w-0">
                                            <span class="fw-semibold d-block text-truncate">{{ $delivery->release->displayTitle() }}</span>
                                            <span class="small text-muted">{{ $delivery->release->artistLine() }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    {{ $delivery->dsp->name }}
                                    @if ($delivery->isLate()) <x-icon name="triangle-alert" size="14" class="text-warning" title="{{ __('Behind the store’s lead time') }}" /> @endif
                                </td>
                                <td><x-badge :variant="$delivery->status->variant()">{{ $delivery->status->label() }}</x-badge></td>
                                <td class="d-none d-md-table-cell small">{{ App\Support\Format::date($delivery->delivered_on) }}</td>
                                <td class="d-none d-md-table-cell small">{{ App\Support\Format::date($delivery->live_on) }}</td>
                            </tr>
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

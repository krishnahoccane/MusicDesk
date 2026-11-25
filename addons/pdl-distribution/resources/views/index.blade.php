<div>
    <x-page-header :title="__('PDL distribution')" :subtitle="__('Releases sent to Phonographic Digital Limited and their review and release status.')">
        @can('pdl.manage')
            <x-slot:actions>
                <a href="{{ route('settings.pdl') }}" class="btn btn-light"><x-icon name="settings" size="16" /> {{ __('Settings') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    @unless ($configured)
        <x-alert variant="warning" class="mb-3">
            {{ __('PDL distribution is not set up or is disabled.') }}
            @can('pdl.manage') <a href="{{ route('settings.pdl') }}">{{ __('Open Settings → PDL distribution') }}</a> @endcan
        </x-alert>
    @endunless

    <div class="row g-3 mb-3">
        @foreach ([['sending', 'loader', 'info'], ['failed', 'circle-alert', 'danger'], ['issue_reported', 'triangle-alert', 'warning'], ['live', 'radio', 'success']] as [$key, $icon, $variant])
            <div class="col-6 col-xl-3">
                <button type="button" class="card ld-stat text-reset text-start w-100 border-0" wire:click="$set('status', '{{ $key }}')">
                    <span class="ld-stat__icon ld-stat__icon--{{ $variant }}"><x-icon :name="$icon" size="20" /></span>
                    <div class="min-w-0">
                        <div class="ld-stat__label">{{ __(\LabelDesk\Addons\PdlDistribution\Models\PdlDelivery::STATUSES[$key][0]) }}</div>
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
                <h2 class="card-title">{{ __('Approved or ready, not sent to PDL') }}</h2>
            </div>
            <ul class="list-unstyled mb-0">
                @foreach ($awaiting as $release)
                    <li class="d-flex align-items-center gap-3 px-4 py-2 border-bottom" wire:key="aw-{{ $release->id }}">
                        <x-release-artwork :release="$release" size="36" />
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('pdl.releases.show', $release) }}" class="fw-semibold">{{ $release->displayTitle() }}</a>
                            <div class="small text-muted">{{ $release->artistLine() }} @if ($release->release_date) &middot; {{ App\Support\Format::date($release->release_date) }} @endif</div>
                        </div>
                        @can('pdl.deliver')
                            <a href="{{ route('pdl.releases.show', $release) }}" class="btn btn-sm btn-light"><x-icon name="send" size="14" /> {{ __('Send to PDL') }}</a>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-7">
                    <div class="input-icon">
                        <x-icon name="search" size="16" />
                        <input type="search" class="form-control" placeholder="{{ __('Release, artist or UPC') }}" wire:model.live.debounce.300ms="search" aria-label="{{ __('Search') }}" maxlength="100">
                    </div>
                </div>
                <div class="col-md-5">
                    <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach (\LabelDesk\Addons\PdlDistribution\Models\PdlDelivery::STATUSES as $key => [$label])
                            <option value="{{ $key }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if ($deliveries->isEmpty())
            <x-empty-state icon="send" :title="__('Nothing sent to PDL yet')" :description="__('Open an approved or ready release above and send it to PDL.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Release') }}</th>
                            <th>{{ __('PDL status') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('PDL album id') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr wire:key="d-{{ $delivery->id }}">
                                <td>
                                    <a href="{{ route('pdl.releases.show', $delivery->release_id) }}" class="fw-semibold">{{ $delivery->release?->displayTitle() }}</a>
                                    <div class="small text-muted">{{ $delivery->release?->artistLine() }}</div>
                                </td>
                                <td><x-badge :variant="$delivery->statusVariant()">{{ $delivery->statusLabel() }}</x-badge></td>
                                <td class="d-none d-md-table-cell font-mono small">{{ $delivery->pdl_album_id ?? '—' }}</td>
                                <td class="d-none d-lg-table-cell small text-muted">{{ App\Support\Format::datetime($delivery->updated_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $deliveries->links() }}</div>
        @endif
    </div>
</div>

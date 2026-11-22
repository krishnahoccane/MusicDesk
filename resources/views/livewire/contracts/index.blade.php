<div>
    <x-page-header :title="__('Contracts')" :subtitle="__('All artist agreements. Add and edit contracts from the artist\'s profile.')" />

    @if ($expiringCount > 0 && $status !== 'expiring')
        <x-alert variant="warning" class="mb-4">
            {{ trans_choice('{1} 1 active contract ends within 60 days.|[2,*] :count active contracts end within 60 days.', $expiringCount, ['count' => $expiringCount]) }}
            <button type="button" class="btn btn-link p-0 ms-1 fw-semibold align-baseline" wire:click="$set('status', 'expiring')">{{ __('Show them') }} &rarr;</button>
        </x-alert>
    @endif

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-icon">
                        <x-icon name="search" size="16" />
                        <input type="search" class="form-control" placeholder="{{ __('Search contract or artist') }}"
                               wire:model.live.debounce.300ms="search" aria-label="{{ __('Search contracts') }}" maxlength="100">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="expiring">{{ __('Ending within 60 days') }}</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select" wire:model.live="type" aria-label="{{ __('Filter by type') }}">
                        <option value="">{{ __('All types') }}</option>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
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

        @if ($contracts->isEmpty())
            <x-empty-state icon="file-signature" :title="$filtered ? __('No contracts found') : __('No contracts yet')"
                           :description="$filtered ? __('Try a different search or filter.') : __('Open an artist and use the Contracts tab to record an agreement.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Artist') }}</th>
                            <th>{{ __('Contract') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Ends') }}</th>
                            <th class="d-none d-md-table-cell text-end">{{ __('Artist share') }}</th>
                            <th class="d-none d-lg-table-cell text-end">{{ __('Advance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contracts as $contract)
                            @php($effective = $contract->effectiveStatus())
                            <tr wire:key="c-{{ $contract->id }}">
                                <td>
                                    <a href="{{ route('artists.show', ['artist' => $contract->artist, 'tab' => 'contracts']) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                        <x-artist-avatar :artist="$contract->artist" size="28" />
                                        <span class="fw-semibold">{{ $contract->artist->name }}</span>
                                    </a>
                                </td>
                                <td>
                                    <div>{{ $contract->title }}</div>
                                    <div class="small text-muted">{{ $contract->type->label() }}</div>
                                </td>
                                <td><x-badge :variant="$effective->variant()">{{ $effective->label() }}</x-badge></td>
                                <td class="small text-nowrap">{{ $contract->end_date ? App\Support\Format::date($contract->end_date) : __('Open-ended') }}</td>
                                <td class="d-none d-md-table-cell text-end">{{ App\Support\Format::percent($contract->artist_share) }}</td>
                                <td class="d-none d-lg-table-cell text-end text-nowrap">{{ App\Support\Format::money($contract->advance_amount, $contract->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($contracts->hasPages())
                <div class="card-body border-top py-2">{{ $contracts->links() }}</div>
            @endif
        @endif
    </div>
</div>

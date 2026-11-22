<div>
    <x-page-header :title="__('Import sales reports')" :subtitle="__('Upload sales and streaming reports from your distributor or stores (CSV or Excel). Matched sales feed the revenue reports, artist earnings and statements.')">
        <x-slot:actions>
            <a href="{{ route('reports.show', ['report' => 'revenue', 'f' => ['by' => 'store']]) }}" class="btn btn-light"><x-icon name="trending-up" size="16" /> {{ __('Revenue report') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-3">
        <div class="card-header">
            <span class="ld-step">1</span>
            <h2 class="card-title">{{ __('Choose the sales period') }}</h2>
        </div>
        <div class="card-body">
            @if ($periods->isEmpty() && ! $creating)
                <p class="small text-muted">{{ __('There is no open royalty period yet. A period is the time span the reports cover, for example a month or a quarter.') }}</p>
            @endif

            <div class="d-flex flex-wrap align-items-end gap-2">
                @if ($periods->isNotEmpty())
                    <div>
                        <label class="form-label small mb-1" for="period">{{ __('Open period') }}</label>
                        <select id="period" class="form-select" style="min-width: 260px" wire:model.live="periodId">
                            @foreach ($periods as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} ({{ App\Support\Format::date($p->starts_on) }} – {{ App\Support\Format::date($p->ends_on) }})</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($canManage && ! $creating)
                    <button type="button" class="btn btn-light" wire:click="startCreating"><x-icon name="plus" size="16" /> {{ __('New period') }}</button>
                @endif
            </div>

            @if ($creating)
                <form class="row g-2 align-items-start mt-3" wire:submit="createPeriod" novalidate>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="name">{{ __('Name') }}</label>
                        <input id="name" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="100">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="starts_on">{{ __('Start') }}</label>
                        <input id="starts_on" type="date" class="form-control @error('starts_on') is-invalid @enderror" wire:model="starts_on">
                        @error('starts_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="ends_on">{{ __('End') }}</label>
                        <input id="ends_on" type="date" class="form-control @error('ends_on') is-invalid @enderror" wire:model="ends_on">
                        @error('ends_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2 d-flex gap-2" style="padding-top: 1.6rem">
                        <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                        <button type="button" class="btn btn-light" wire:click="$set('creating', false)">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if ($period)
        <div class="mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="ld-step">2</span>
                <h2 class="h6 mb-0" style="color: var(--ld-heading)">{{ __('Upload, map columns and process') }}</h2>
                @can('royalties.view')
                    <a href="{{ route('royalties.periods.show', $period) }}" class="small ms-auto">{{ __('Open period :name', ['name' => $period->name]) }} &rarr;</a>
                @endcan
            </div>
            <livewire:royalties.imports :period="$period" :key="'sales-imports-'.$period->id" />
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <x-icon name="history" size="18" />
            <h2 class="card-title">{{ __('Recent imports') }}</h2>
        </div>
        @if ($history->isEmpty())
            <div class="card-body small text-muted">{{ __('No sales reports imported yet.') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Report') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Period') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end d-none d-lg-table-cell">{{ __('Rows matched') }}</th>
                            <th class="text-end">{{ __('Net revenue') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $import)
                            <tr wire:key="import-{{ $import->id }}">
                                <td>
                                    <span class="d-block fw-semibold small">{{ $import->source_name }}</span>
                                    <span class="ld-list__meta">{{ $import->original_name }} &middot; {{ $import->created_at?->diffForHumans() }}</span>
                                </td>
                                <td class="d-none d-md-table-cell small">
                                    @can('royalties.view')
                                        <a href="{{ route('royalties.periods.show', $import->royalty_period_id) }}">{{ $import->period?->name }}</a>
                                    @else
                                        {{ $import->period?->name }}
                                    @endcan
                                </td>
                                <td><x-badge :variant="$import->status->variant()">{{ $import->status->label() }}</x-badge></td>
                                <td class="text-end small d-none d-lg-table-cell">{{ number_format($import->matched_rows) }} / {{ number_format($import->total_rows) }}</td>
                                <td class="text-end text-nowrap small">{{ App\Support\Format::money((string) $import->net_total_base, $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

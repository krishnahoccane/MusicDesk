<div>
    <x-page-header :title="__('Royalties')" :subtitle="__('Import sales statements per period, check the matching, then lock the period to credit artists.')">
        <x-slot:actions>
            @can('payments.view')
                <a href="{{ route('royalties.balances') }}" class="btn btn-light"><x-icon name="scale" size="16" /> {{ __('Artist balances') }}</a>
            @endcan
            @if ($canManage && ! $creating)
                <button type="button" class="btn btn-primary" wire:click="startCreating"><x-icon name="plus" size="16" /> {{ __('New period') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($creating)
        <form wire:submit="create" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="calendar-range" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('New royalty period') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="p-name" class="form-label">{{ __('Name') }}</label>
                        <input id="p-name" type="text" wire:model="name" maxlength="100" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <label for="p-start" class="form-label">{{ __('From') }}</label>
                        <input id="p-start" type="date" wire:model="starts_on" @class(['form-control', 'is-invalid' => $errors->has('starts_on')])>
                        @error('starts_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <label for="p-end" class="form-label">{{ __('To') }}</label>
                        <input id="p-end" type="date" wire:model="ends_on" @class(['form-control', 'is-invalid' => $errors->has('ends_on')])>
                        @error('ends_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="p-notes" class="form-label">{{ __('Notes') }}</label>
                        <input id="p-notes" type="text" wire:model="notes" maxlength="1000" class="form-control">
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="$set('creating', false)">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Create period') }}</button>
            </div>
        </form>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                @if ($periods->isEmpty())
                    <x-empty-state icon="wallet" :title="__('No royalty periods yet')"
                                   :description="__('Create a period (for example a quarter), upload the sales statements you received for it, and LabelDesk calculates what each artist earned.')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Period') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Statements') }}</th>
                                    <th class="text-end">{{ __('Revenue') }}</th>
                                    <th class="text-end d-none d-lg-table-cell">{{ __('To artists') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($periods as $period)
                                    <tr wire:key="p-{{ $period->id }}">
                                        <td>
                                            <a href="{{ route('royalties.periods.show', $period) }}" class="fw-semibold">{{ $period->name }}</a>
                                            <div class="small text-muted">{{ App\Support\Format::date($period->starts_on) }} – {{ App\Support\Format::date($period->ends_on) }}</div>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            {{ $period->active_imports_count }}
                                            @if (($unmatched[$period->id] ?? 0) > 0)
                                                <x-badge variant="warning" class="ms-1">{{ trans_choice('{1} 1 unmatched|[2,*] :count unmatched', $unmatched[$period->id], ['count' => number_format($unmatched[$period->id])]) }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">{{ App\Support\Format::money($period->revenue_total !== null ? (string) $period->revenue_total : '0', $currency) }}</td>
                                        <td class="text-end text-nowrap d-none d-lg-table-cell">{{ App\Support\Format::money($period->artist_total !== null ? (string) $period->artist_total : '0', $currency) }}</td>
                                        <td>
                                            @if ($period->isLocked())
                                                <x-badge variant="success" icon="lock">{{ __('Locked') }}</x-badge>
                                            @else
                                                <x-badge variant="info">{{ __('Open') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ route('royalties.periods.show', $period) }}" class="btn btn-sm btn-light" aria-label="{{ __('Open :name', ['name' => $period->name]) }}"><x-icon name="chevron-right" size="14" /></a>
                                            @if ($canManage && ! $period->isLocked() && $period->active_imports_count === 0)
                                                <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $period->id }})"
                                                        wire:confirm="{{ __('Delete the empty period :name?', ['name' => $period->name]) }}" aria-label="{{ __('Delete :name', ['name' => $period->name]) }}"><x-icon name="trash-2" size="14" /></button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="percent" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Default artist share') }}</h2>
                </div>
                <div class="card-body">
                    <p class="small text-muted">{{ __('Used when an artist has no recording, distribution or license contract with an artist share on the sale date. The label keeps the rest as commission.') }}</p>
                    @if ($canManage)
                        <form wire:submit="saveDefaultShare" class="d-flex gap-2 align-items-start" novalidate>
                            <div>
                                <div class="input-group" style="max-width: 160px">
                                    <input type="text" inputmode="decimal" wire:model="defaultShare" maxlength="8" aria-label="{{ __('Default artist share') }}"
                                           @class(['form-control', 'is-invalid' => $errors->has('defaultShare')])>
                                    <span class="input-group-text">%</span>
                                </div>
                                @error('defaultShare') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                            </div>
                            <button type="submit" class="btn btn-light">{{ __('Save') }}</button>
                        </form>
                    @else
                        <div class="fs-4 fw-semibold">{{ $defaultShare }}%</div>
                    @endif
                </div>
            </div>
            <div class="card">
                <div class="card-body small text-muted">
                    <p class="fw-semibold text-body mb-2">{{ __('How it works') }}</p>
                    <ol class="ps-3 mb-0">
                        <li>{{ __('Upload each store or distributor statement (CSV or XLSX) and map its columns once.') }}</li>
                        <li>{{ __('Sales are matched to tracks by ISRC (or releases by UPC). Match the rest by hand.') }}</li>
                        <li>{{ __('Revenue is split between the primary artists and by their contract share.') }}</li>
                        <li>{{ __('Lock the period to credit each artist’s account. Record payments from the artist’s page.') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

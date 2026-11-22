<div>
    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-4">
            <x-stat-card :label="__('Account balance')" :value="App\Support\Format::money($balance, $currency)" icon="wallet"
                         :variant="App\Support\Decimal::compare($balance, '0') > 0 ? 'success' : (App\Support\Decimal::compare($balance, '0') < 0 ? 'warning' : null)"
                         :hint="App\Support\Decimal::compare($balance, '0') > 0 ? __('owed to the artist') : (App\Support\Decimal::compare($balance, '0') < 0 ? __('still to be recouped') : __('settled'))" />
        </div>
        @if ($canManage)
            <div class="col-md-6 col-xl-8 d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-primary" wire:click="startRecording('payment')"><x-icon name="banknote" size="16" /> {{ __('Record payment') }}</button>
                <button type="button" class="btn btn-light" wire:click="startRecording('advance')">{{ __('Record advance') }}</button>
                <button type="button" class="btn btn-light" wire:click="startRecording('expense')">{{ __('Record expense') }}</button>
                <button type="button" class="btn btn-light" wire:click="startRecording('adjustment')">{{ __('Adjustment') }}</button>
            </div>
        @endif
    </div>

    @if ($recording)
        <form wire:submit="record" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="banknote" size="18" class="text-muted" />
                <h2 class="card-title">{{ $types[$type] ?? '' }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-md-3">
                        <label for="le-type" class="form-label">{{ __('Type') }}</label>
                        <select id="le-type" wire:model.live="type" class="form-select">
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="le-amount" class="form-label">{{ __('Amount') }} ({{ $currency }})</label>
                        <input id="le-amount" type="text" inputmode="decimal" wire:model="amount" maxlength="20" @class(['form-control', 'is-invalid' => $errors->has('amount')])>
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    @if ($type === 'adjustment')
                        <div class="col-sm-6 col-md-3">
                            <label for="le-dir" class="form-label">{{ __('Direction') }}</label>
                            <select id="le-dir" wire:model="direction" class="form-select">
                                <option value="credit">{{ __('Add to the balance (owed to artist)') }}</option>
                                <option value="debit">{{ __('Subtract from the balance') }}</option>
                            </select>
                        </div>
                    @endif
                    <div class="col-sm-6 col-md-3">
                        <label for="le-date" class="form-label">{{ __('Date') }}</label>
                        <input id="le-date" type="date" wire:model="entry_date" @class(['form-control', 'is-invalid' => $errors->has('entry_date')])>
                    </div>
                    <div class="col-md-6">
                        <label for="le-desc" class="form-label">{{ __('Description') }}</label>
                        <input id="le-desc" type="text" wire:model="description" maxlength="255" @class(['form-control', 'is-invalid' => $errors->has('description')])
                               placeholder="{{ $type === 'payment' ? __('e.g. Q4 2026 royalty payment') : '' }}">
                        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    @if ($type === 'payment' || $type === 'advance')
                        <div class="col-sm-6 col-md-3">
                            <label for="le-method" class="form-label">{{ __('Method') }}</label>
                            <input id="le-method" type="text" wire:model="method" maxlength="50" class="form-control" placeholder="{{ __('e.g. Bank transfer') }}">
                        </div>
                    @endif
                    <div class="col-sm-6 col-md-3">
                        <label for="le-ref" class="form-label">{{ __('Reference') }}</label>
                        <input id="le-ref" type="text" wire:model="reference" maxlength="100" @class(['form-control', 'is-invalid' => $errors->has('reference')])>
                    </div>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    @if ($type === 'payment') {{ __('Payments cannot exceed the current balance.') }}
                    @elseif (in_array($type, ['advance', 'expense'], true)) {{ __('Recouped automatically from future earnings: the balance goes negative until earnings cover it.') }}
                    @else {{ __('Use adjustments to correct mistakes; entries are never edited or deleted.') }} @endif
                </p>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="$set('recording', false)">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="record">{{ __('Save') }}</button>
            </div>
        </form>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <x-icon name="book-open" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Account history') }}</h2>
                </div>
                @if ($entries->isEmpty())
                    <x-empty-state icon="wallet" :title="__('No entries yet')" :description="__('Earnings are added when a royalty period is locked.')" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Entry') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $entry)
                                    <tr wire:key="le-{{ $entry->id }}">
                                        <td class="small text-nowrap">{{ App\Support\Format::date($entry->entry_date) }}</td>
                                        <td>
                                            <div>{{ $entry->description }}</div>
                                            <div class="small text-muted">
                                                {{ $entry->type->label() }}@if ($entry->method) &middot; {{ $entry->method }}@endif
                                                @if ($entry->reference && ! str_starts_with($entry->reference, 'reversal:')) &middot; {{ $entry->reference }}@endif
                                                &middot; {{ $entry->creator?->name ?? __('System') }}
                                            </div>
                                        </td>
                                        <td @class(['text-end text-nowrap fw-semibold', 'text-success' => App\Support\Decimal::compare((string) $entry->amount, '0') > 0])>{{ App\Support\Format::money((string) $entry->amount, $currency) }}</td>
                                        <td class="text-end">
                                            @if ($canManage && $entry->type !== App\Enums\LedgerEntryType::Earnings && ! str_starts_with((string) $entry->reference, 'reversal:') && ! in_array($entry->id, $reversed, true))
                                                <button type="button" class="btn btn-sm btn-light" wire:click="reverse({{ $entry->id }})"
                                                        wire:confirm="{{ __('Reverse this entry? An opposite adjustment is added; nothing is deleted.') }}">{{ __('Reverse') }}</button>
                                            @elseif (in_array($entry->id, $reversed, true))
                                                <x-badge>{{ __('Reversed') }}</x-badge>
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
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <x-icon name="file-text" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Statements') }}</h2>
                </div>
                <ul class="list-unstyled mb-0">
                    @forelse ($periods as $period)
                        <li class="d-flex align-items-center gap-2 px-4 py-2 border-bottom" wire:key="st-{{ $period->id }}">
                            <a href="{{ route('royalties.statements.show', [$period, $artist]) }}" class="flex-grow-1">{{ $period->name }}</a>
                            @unless ($period->isLocked()) <x-badge variant="info">{{ __('Provisional') }}</x-badge> @endunless
                        </li>
                    @empty
                        <li class="px-4 py-3 small text-muted">{{ __('No royalty periods include this artist yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

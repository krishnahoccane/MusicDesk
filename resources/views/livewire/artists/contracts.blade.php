<div>
    @if ($showForm)
        <form wire:submit="save" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="file-signature" size="18" class="text-muted" />
                <h2 class="card-title">{{ $editingId ? __('Edit contract') : __('Add contract') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="c-title" class="form-label">{{ __('Title') }}</label>
                        <input id="c-title" type="text" wire:model="title" maxlength="200" placeholder="{{ __('e.g. Album deal 2027') }}"
                               @class(['form-control', 'is-invalid' => $errors->has('title')])>
                        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-type" class="form-label">{{ __('Type') }}</label>
                        <select id="c-type" wire:model="type" class="form-select">
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-status" class="form-label">{{ __('Status') }}</label>
                        <select id="c-status" wire:model="status" class="form-select">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-start" class="form-label">{{ __('Start date') }}</label>
                        <input id="c-start" type="date" wire:model="start_date" @class(['form-control', 'is-invalid' => $errors->has('start_date')])>
                        @error('start_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-end" class="form-label">{{ __('End date') }}</label>
                        <input id="c-end" type="date" wire:model="end_date" @class(['form-control', 'is-invalid' => $errors->has('end_date')])>
                        <div class="form-text">{{ __('Leave empty for open-ended.') }}</div>
                        @error('end_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-share" class="form-label">{{ __('Artist share') }}</label>
                        <div class="input-group">
                            <input id="c-share" type="text" inputmode="decimal" wire:model="artist_share" maxlength="9"
                                   @class(['form-control', 'is-invalid' => $errors->has('artist_share')])>
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">{{ __('Share of net royalties paid to the artist.') }}</div>
                        @error('artist_share') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-advance" class="form-label">{{ __('Advance') }}</label>
                        <div class="input-group">
                            <input id="c-advance" type="text" inputmode="decimal" wire:model="advance_amount" maxlength="20"
                                   @class(['form-control', 'is-invalid' => $errors->has('advance_amount')])>
                            <select wire:model="currency" class="form-select" style="max-width: 95px" aria-label="{{ __('Currency') }}">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('advance_amount') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @error('currency') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="c-territory" class="form-label">{{ __('Territory') }}</label>
                        <input id="c-territory" type="text" wire:model="territory" maxlength="150" class="form-control">
                    </div>
                    <div class="col-12">
                        <label for="c-notes" class="form-label">{{ __('Notes') }}</label>
                        <textarea id="c-notes" wire:model="notes" rows="3" maxlength="5000" @class(['form-control', 'is-invalid' => $errors->has('notes')])></textarea>
                        @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <p class="small text-muted mt-3 mb-0">{{ __('Attach the signed agreement on the Documents tab.') }}</p>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save contract') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="card-header">
            <x-icon name="file-signature" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Contracts') }}</h2>
            @if ($canManage && ! $showForm)
                <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="create"><x-icon name="plus" size="14" /> {{ __('Add contract') }}</button>
            @endif
        </div>
        @if ($contracts->isEmpty())
            <x-empty-state icon="file-signature" :title="__('No contracts yet')" :description="__('Record the agreements you have with this artist: dates, royalty share and advances.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Contract') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Term') }}</th>
                            <th class="d-none d-md-table-cell text-end">{{ __('Artist share') }}</th>
                            <th class="d-none d-lg-table-cell text-end">{{ __('Advance') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contracts as $contract)
                            @php($effective = $contract->effectiveStatus())
                            <tr wire:key="contract-{{ $contract->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $contract->title }}</div>
                                    <div class="small text-muted">
                                        {{ $contract->type->label() }}@if ($contract->territory) &middot; {{ $contract->territory }}@endif
                                        @if ($contract->documents_count) &middot; <x-icon name="paperclip" size="12" /> {{ $contract->documents_count }} @endif
                                    </div>
                                </td>
                                <td><x-badge :variant="$effective->variant()">{{ $effective->label() }}</x-badge></td>
                                <td class="small text-nowrap">
                                    {{ App\Support\Format::date($contract->start_date) }} &ndash; {{ $contract->end_date ? App\Support\Format::date($contract->end_date) : __('open-ended') }}
                                </td>
                                <td class="d-none d-md-table-cell text-end">{{ App\Support\Format::percent($contract->artist_share) }}</td>
                                <td class="d-none d-lg-table-cell text-end text-nowrap">{{ App\Support\Format::money($contract->advance_amount, $contract->currency) }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($canManage)
                                        <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $contract->id }})" aria-label="{{ __('Edit :title', ['title' => $contract->title]) }}"><x-icon name="pencil" size="14" /></button>
                                        <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $contract->id }})"
                                                wire:confirm="{{ __('Delete the contract ":title"? Attached documents stay on the artist.', ['title' => $contract->title]) }}"
                                                aria-label="{{ __('Delete :title', ['title' => $contract->title]) }}"><x-icon name="trash-2" size="14" /></button>
                                    @endif
                                </td>
                            </tr>
                            @if ($contract->notes)
                                <tr wire:key="contract-{{ $contract->id }}-notes"><td colspan="6" class="small text-muted pt-0 border-top-0" style="white-space: pre-line">{{ $contract->notes }}</td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

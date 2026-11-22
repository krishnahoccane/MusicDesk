<div>
    <x-page-header :title="__('Stores')" :subtitle="__('Stores and streaming services you deliver releases to.')">
        @unless ($showForm)
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="create"><x-icon name="plus" size="16" /> {{ __('Add store') }}</button>
            </x-slot:actions>
        @endunless
    </x-page-header>

    @if ($showForm)
        <form wire:submit="save" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="store" size="18" class="text-muted" />
                <h2 class="card-title">{{ $editingId ? __('Edit store') : __('Add store') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="dsp-name" class="form-label">{{ __('Name') }}</label>
                        <input id="dsp-name" type="text" wire:model="name" maxlength="100" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="dsp-website" class="form-label">{{ __('Website') }}</label>
                        <input id="dsp-website" type="url" wire:model="website" maxlength="255" placeholder="https://" @class(['form-control', 'is-invalid' => $errors->has('website')])>
                        @error('website') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-4 col-md-3">
                        <label for="dsp-lead" class="form-label">{{ __('Lead time') }}</label>
                        <div class="input-group">
                            <input id="dsp-lead" type="number" min="0" max="365" wire:model="lead_time_days" @class(['form-control', 'is-invalid' => $errors->has('lead_time_days')])>
                            <span class="input-group-text">{{ __('days') }}</span>
                        </div>
                        <div class="form-text">{{ __('Deliver this many days before the release date.') }}</div>
                        @error('lead_time_days') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-8 col-md-9">
                        <label for="dsp-notes" class="form-label">{{ __('Delivery notes') }}</label>
                        <input id="dsp-notes" type="text" wire:model="delivery_notes" maxlength="500" placeholder="{{ __('e.g. delivered via our distributor’s portal') }}" class="form-control">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="dsp-active" wire:model="is_active">
                            <label class="form-check-label" for="dsp-active">{{ __('Active (offered when adding stores to a release)') }}</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Store') }}</th>
                        <th class="d-none d-md-table-cell">{{ __('Lead time') }}</th>
                        <th class="text-end">{{ __('Deliveries') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dsps as $dsp)
                        <tr wire:key="dsp-{{ $dsp->id }}">
                            <td>
                                <div class="fw-semibold">{{ $dsp->name }}</div>
                                @if ($dsp->delivery_notes) <div class="small text-muted">{{ $dsp->delivery_notes }}</div> @endif
                            </td>
                            <td class="d-none d-md-table-cell">{{ $dsp->lead_time_days !== null ? trans_choice('{1} 1 day|[0,*] :count days', $dsp->lead_time_days, ['count' => $dsp->lead_time_days]) : '—' }}</td>
                            <td class="text-end">{{ $dsp->deliveries_count }}</td>
                            <td><x-badge :variant="$dsp->is_active ? 'success' : null">{{ $dsp->is_active ? __('Active') : __('Inactive') }}</x-badge></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $dsp->id }})" aria-label="{{ __('Edit :name', ['name' => $dsp->name]) }}"><x-icon name="pencil" size="14" /></button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="toggleActive({{ $dsp->id }})">{{ $dsp->is_active ? __('Deactivate') : __('Activate') }}</button>
                                @if ($dsp->deliveries_count === 0)
                                    <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $dsp->id }})"
                                            wire:confirm="{{ __('Delete :name?', ['name' => $dsp->name]) }}" aria-label="{{ __('Delete :name', ['name' => $dsp->name]) }}"><x-icon name="trash-2" size="14" /></button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div>
    <x-page-header :title="__('Labels')" :subtitle="__('Sub-labels and imprints under :label.', ['label' => $mainLabel ?: __('your label')])">
        @if ($canManage && ! $showForm)
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="create"><x-icon name="plus" size="16" /> {{ __('Add label') }}</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($showForm)
        <form wire:submit="save" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="tags" size="18" class="text-muted" />
                <h2 class="card-title">{{ $editingId ? __('Edit label') : __('Add label') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="l-name" class="form-label">{{ __('Label name') }}</label>
                        <input id="l-name" type="text" wire:model="name" maxlength="150" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="l-parent" class="form-label">{{ __('Part of') }}</label>
                        <select id="l-parent" wire:model="parent_id" @class(['form-select', 'is-invalid' => $errors->has('parent_id')])>
                            <option value="">{{ $mainLabel ?: __('Main label') }} ({{ __('main label') }})</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->path() }}</option>
                            @endforeach
                        </select>
                        @error('parent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="l-code" class="form-label">{{ __('Label code') }}</label>
                        <input id="l-code" type="text" wire:model="code" maxlength="20" placeholder="{{ __('e.g. NOVA') }}" @class(['form-control font-monospace text-uppercase', 'is-invalid' => $errors->has('code')])>
                        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">{{ __('Optional catalogue-number prefix.') }}</div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="l-country" class="form-label">{{ __('Country') }}</label>
                        <input id="l-country" type="text" wire:model="country" maxlength="2" placeholder="GB" @class(['form-control text-uppercase', 'is-invalid' => $errors->has('country')])>
                        @error('country') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="l-contact" class="form-label">{{ __('Contact person') }}</label>
                        <input id="l-contact" type="text" wire:model="contact_name" maxlength="150" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label for="l-email" class="form-label">{{ __('Email') }}</label>
                        <input id="l-email" type="email" wire:model="email" maxlength="255" @class(['form-control', 'is-invalid' => $errors->has('email')])>
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="l-website" class="form-label">{{ __('Website') }}</label>
                        <input id="l-website" type="url" wire:model="website" maxlength="255" placeholder="https://" @class(['form-control', 'is-invalid' => $errors->has('website')])>
                        @error('website') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="l-notes" class="form-label">{{ __('Notes') }}</label>
                        <textarea id="l-notes" wire:model="notes" rows="2" maxlength="2000" class="form-control"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="l-active" wire:model="is_active">
                            <label class="form-check-label" for="l-active">{{ __('Active (can be chosen for releases)') }}</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="card-body border-bottom py-3 d-flex flex-wrap gap-2">
            <div class="input-icon flex-grow-1" style="max-width: 420px">
                <x-icon name="search" size="16" />
                <input type="search" class="form-control" placeholder="{{ __('Search name, code or email') }}" wire:model.live.debounce.300ms="search" aria-label="{{ __('Search labels') }}" maxlength="100">
            </div>
            <select class="form-select" style="max-width: 180px" wire:model.live="status" aria-label="{{ __('Status') }}">
                <option value="">{{ __('All labels') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="inactive">{{ __('Inactive / awaiting approval') }}</option>
            </select>
        </div>
        @if ($labels->isEmpty())
            <x-empty-state icon="tags" :title="$search !== '' || $status !== '' ? __('No labels found') : __('No sub-labels yet')"
                           :description="__('Add the sub-labels and imprints you release music under. Each release can then be assigned to one of them.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Label') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Part of') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('Contact') }}</th>
                            <th class="text-end">{{ __('Releases') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($labels as $label)
                            <tr wire:key="label-{{ $label->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $label->name }} @if ($label->code) <span class="font-mono small text-muted">{{ $label->code }}</span> @endif</div>
                                    @if ($label->website) <div class="small text-muted text-truncate" style="max-width: 280px">{{ $label->website }}</div> @endif
                                </td>
                                <td class="d-none d-md-table-cell small">{{ $label->parent?->name ?? ($mainLabel ?: __('Main label')) }}</td>
                                <td class="d-none d-lg-table-cell small">
                                    {{ $label->contact_name ?? '—' }}
                                    @if ($label->email) <div class="text-muted">{{ $label->email }}</div> @endif
                                    @if ($label->owner) <div class="text-muted">{{ __('Signed up by :name', ['name' => $label->owner->name]) }}</div> @endif
                                </td>
                                <td class="text-end">{{ $label->releases_count }}</td>
                                <td>
                                    @if ($label->is_active)
                                        <x-badge variant="success">{{ __('Active') }}</x-badge>
                                    @else
                                        <x-badge>{{ __('Inactive') }}</x-badge>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canManage)
                                        <button type="button" class="btn btn-sm btn-light" wire:click="toggle({{ $label->id }})">{{ $label->is_active ? __('Deactivate') : __('Activate') }}</button>
                                        <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $label->id }})" aria-label="{{ __('Edit :name', ['name' => $label->name]) }}"><x-icon name="pencil" size="14" /></button>
                                        @if ($label->releases_count === 0 && $label->children_count === 0)
                                            <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $label->id }})"
                                                    wire:confirm="{{ __('Delete :name?', ['name' => $label->name]) }}" aria-label="{{ __('Delete :name', ['name' => $label->name]) }}"><x-icon name="trash-2" size="14" /></button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($labels->hasPages())
                <div class="card-body border-top py-2">{{ $labels->links() }}</div>
            @endif
        @endif
    </div>
</div>

<div>
    @if ($showForm)
        <form wire:submit="save" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="user-round-plus" size="18" class="text-muted" />
                <h2 class="card-title">{{ $editingId ? __('Edit contact') : __('Add contact') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="contact-name" class="form-label">{{ __('Name') }}</label>
                        <input id="contact-name" type="text" wire:model="name" maxlength="150" autocomplete="off"
                               @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="contact-role" class="form-label">{{ __('Role') }}</label>
                        <select id="contact-role" wire:model="role" class="form-select">
                            @foreach ($roles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="contact-email" class="form-label">{{ __('Email') }}</label>
                        <input id="contact-email" type="email" wire:model="email" maxlength="255" autocomplete="off"
                               @class(['form-control', 'is-invalid' => $errors->has('email')])>
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="contact-phone" class="form-label">{{ __('Phone') }}</label>
                        <input id="contact-phone" type="tel" wire:model="phone" maxlength="40" autocomplete="off"
                               @class(['form-control', 'is-invalid' => $errors->has('phone')])>
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="contact-notes" class="form-label">{{ __('Notes') }}</label>
                        <input id="contact-notes" type="text" wire:model="notes" maxlength="500"
                               @class(['form-control', 'is-invalid' => $errors->has('notes')])>
                        @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="contact-primary" wire:model="is_primary">
                            <label class="form-check-label" for="contact-primary">{{ __('Primary contact for this artist') }}</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save contact') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="card-header">
            <x-icon name="contact" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Contacts') }}</h2>
            @if ($canEdit && ! $showForm)
                <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="create"><x-icon name="plus" size="14" /> {{ __('Add contact') }}</button>
            @endif
        </div>
        @if ($contacts->isEmpty())
            <x-empty-state icon="contact" :title="__('No contacts yet')" :description="__('Add managers, agents and other people you work with for this artist.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Role') }}</th>
                            <th>{{ __('Email') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Phone') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contacts as $contact)
                            <tr wire:key="contact-{{ $contact->id }}">
                                <td>
                                    <span class="fw-semibold">{{ $contact->name }}</span>
                                    @if ($contact->is_primary) <x-badge variant="accent" class="ms-1">{{ __('Primary') }}</x-badge> @endif
                                    @if ($contact->notes) <div class="small text-muted">{{ $contact->notes }}</div> @endif
                                </td>
                                <td>{{ $contact->role->label() }}</td>
                                <td>@if ($contact->email)<a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a>@else — @endif</td>
                                <td class="d-none d-md-table-cell">{{ $contact->phone ?? '—' }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($canEdit)
                                        <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $contact->id }})" aria-label="{{ __('Edit :name', ['name' => $contact->name]) }}"><x-icon name="pencil" size="14" /></button>
                                        <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $contact->id }})"
                                                wire:confirm="{{ __('Remove :name from this artist\'s contacts?', ['name' => $contact->name]) }}"
                                                aria-label="{{ __('Remove :name', ['name' => $contact->name]) }}"><x-icon name="trash-2" size="14" /></button>
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

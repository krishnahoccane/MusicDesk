<div>
    <x-page-header :title="__('Contributors')" :subtitle="__('Songwriters, producers, engineers and other people credited on your tracks.')">
        @if ($canManage && ! $showForm)
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="create"><x-icon name="plus" size="16" /> {{ __('Add contributor') }}</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($showForm)
        <form wire:submit="save" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="user-round-plus" size="18" class="text-muted" />
                <h2 class="card-title">{{ $editingId ? __('Edit contributor') : __('Add contributor') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="c-name" class="form-label">{{ __('Name (as credited)') }}</label>
                        <input id="c-name" type="text" wire:model="name" maxlength="150" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="c-legal" class="form-label">{{ __('Legal name') }}</label>
                        <input id="c-legal" type="text" wire:model="legal_name" maxlength="200" @class(['form-control', 'is-invalid' => $errors->has('legal_name')])>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-ipi" class="form-label">{{ __('IPI number') }}</label>
                        <input id="c-ipi" type="text" inputmode="numeric" wire:model="ipi_number" maxlength="13" @class(['form-control font-monospace', 'is-invalid' => $errors->has('ipi_number')])>
                        @error('ipi_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="c-pro" class="form-label">{{ __('PRO') }}</label>
                        <input id="c-pro" type="text" wire:model="pro" maxlength="60" placeholder="{{ __('e.g. PRS, ASCAP, IPRS') }}" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label for="c-email" class="form-label">{{ __('Email') }}</label>
                        <input id="c-email" type="email" wire:model="email" maxlength="255" @class(['form-control', 'is-invalid' => $errors->has('email')])>
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="c-notes" class="form-label">{{ __('Notes') }}</label>
                        <input id="c-notes" type="text" wire:model="notes" maxlength="500" class="form-control">
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
        <div class="card-body border-bottom py-3">
            <div class="input-icon" style="max-width: 420px">
                <x-icon name="search" size="16" />
                <input type="search" class="form-control" placeholder="{{ __('Search name or IPI') }}" wire:model.live.debounce.300ms="search" aria-label="{{ __('Search contributors') }}" maxlength="100">
            </div>
        </div>
        @if ($contributors->isEmpty())
            <x-empty-state icon="users-round" :title="$search !== '' ? __('No contributors found') : __('No contributors yet')"
                           :description="__('Add songwriters and producers here or directly from a track.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('IPI') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('PRO') }}</th>
                            <th class="text-end">{{ __('Credits') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contributors as $contributor)
                            <tr wire:key="contrib-{{ $contributor->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $contributor->name }}</div>
                                    @if ($contributor->legal_name) <div class="small text-muted">{{ $contributor->legal_name }}</div> @endif
                                </td>
                                <td class="d-none d-md-table-cell font-monospace small">{{ $contributor->ipi_number ?? '—' }}</td>
                                <td class="d-none d-md-table-cell">{{ $contributor->pro ?? '—' }}</td>
                                <td class="text-end">{{ $contributor->credits_count }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($canManage)
                                        <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $contributor->id }})" aria-label="{{ __('Edit :name', ['name' => $contributor->name]) }}"><x-icon name="pencil" size="14" /></button>
                                        @if ($contributor->credits_count === 0)
                                            <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $contributor->id }})"
                                                    wire:confirm="{{ __('Delete :name?', ['name' => $contributor->name]) }}" aria-label="{{ __('Delete :name', ['name' => $contributor->name]) }}"><x-icon name="trash-2" size="14" /></button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($contributors->hasPages())
                <div class="card-body border-top py-2">{{ $contributors->links() }}</div>
            @endif
        @endif
    </div>
</div>

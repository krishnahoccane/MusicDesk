<div>
    <x-page-header :title="$editing ? $role->name : __('New role')"
                   :subtitle="$superAdmin ? __('Super Admins can do everything, including managing the license.') : __('Choose what people with this role can see and do.')" />

    @error('form')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    @if ($superAdmin)
        <x-alert variant="info" class="mb-4">{{ __('The Super Admin role always has every permission and cannot be changed.') }}</x-alert>
    @elseif (! $canEdit)
        <x-alert variant="info" class="mb-4">{{ __('You can view this role but not change it.') }}</x-alert>
    @endif

    <form wire:submit="save" novalidate>
        <div class="card mb-3">
            <div class="card-body">
                <label for="name" class="form-label">{{ __('Role name') }}</label>
                <input id="name" type="text" wire:model="name" maxlength="60" style="max-width: 420px"
                       @class(['form-control', 'is-invalid' => $errors->has('name')]) @disabled(! $canRename) required>
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                @if ($system && $canEdit)
                    <div class="form-text">{{ __('Built-in roles keep their names. You can change their permissions.') }}</div>
                @endif
            </div>
        </div>

        <div class="row g-3">
            @foreach ($groups as $key => $group)
                @php($names = array_keys($group['permissions']))
                @php($selected = $superAdmin ? count($names) : count(array_intersect($names, $permissions)))
                <div class="col-md-6 col-xl-4" wire:key="group-{{ $key }}">
                    <div class="card h-100">
                        <div class="card-header">
                            <h2 class="card-title">{{ $group['label'] }}</h2>
                            <span class="small text-muted ms-auto">{{ $selected }}/{{ count($names) }}</span>
                            @if ($canEdit)
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2" wire:click="toggleGroup('{{ $key }}')">
                                    {{ $selected === count($names) ? __('None') : __('All') }}
                                </button>
                            @endif
                        </div>
                        <div class="card-body py-2">
                            @foreach ($group['permissions'] as $permission => $label)
                                @php($locked = $held !== null && ! in_array($permission, $held, true) && ! in_array($permission, $permissions, true))
                                <div class="form-check py-1">
                                    <input class="form-check-input" type="checkbox" id="perm-{{ $permission }}" value="{{ $permission }}"
                                           wire:model.live="permissions"
                                           @checked($superAdmin)
                                           @disabled(! $canEdit || $locked)>
                                    <label class="form-check-label" for="perm-{{ $permission }}">
                                        {{ $label }}
                                        @if ($locked)
                                            <x-icon name="lock" size="12" class="text-subtle" />
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($held !== null && $canEdit)
            <p class="small text-muted mt-3 mb-0"><x-icon name="lock" size="12" /> {{ __('You can only grant permissions you have yourself.') }}</p>
        @endif

        <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="{{ route('roles.index') }}" class="btn btn-light">{{ $canEdit ? __('Cancel') : __('Back') }}</a>
            @if ($canEdit)
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    {{ __('Save role') }}
                </button>
            @endif
        </div>
    </form>
</div>

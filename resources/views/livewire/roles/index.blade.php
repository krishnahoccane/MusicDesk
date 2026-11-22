<div>
    <x-page-header :title="__('Roles')" :subtitle="__('Roles group permissions. Every user has one role.')">
        @can('create', Spatie\Permission\Models\Role::class)
            <x-slot:actions>
                @if ($customRolesEnabled)
                    <a href="{{ route('roles.create') }}" class="btn btn-primary"><x-icon name="plus" size="16" /> {{ __('New role') }}</a>
                @else
                    <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="{{ __('Custom roles are not included in your license plan.') }}">
                        <button type="button" class="btn btn-primary" disabled><x-icon name="lock" size="16" /> {{ __('New role') }}</button>
                    </span>
                @endif
            </x-slot:actions>
        @endcan
    </x-page-header>

    @unless ($customRolesEnabled)
        <x-alert variant="info" class="mb-4">
            {{ __('Your plan includes the built-in roles. You can adjust their permissions; creating your own roles requires a plan with custom roles.') }}
        </x-alert>
    @endunless

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Role') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th>{{ __('Permissions') }}</th>
                        <th>{{ __('Users') }}</th>
                        <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roles as $role)
                        @php($system = App\Services\RoleService::isSystem($role))
                        @php($superAdmin = App\Services\RoleService::isSuperAdmin($role))
                        <tr wire:key="role-{{ $role->id }}">
                            <td class="fw-semibold">
                                <a href="{{ route('roles.edit', $role) }}" class="text-reset">{{ $role->name }}</a>
                            </td>
                            <td>
                                @if ($system)
                                    <x-badge variant="info" icon="shield">{{ __('Built-in') }}</x-badge>
                                @else
                                    <x-badge>{{ __('Custom') }}</x-badge>
                                @endif
                            </td>
                            <td class="text-muted">
                                {{ $superAdmin ? __('All') : __(':count of :total', ['count' => $role->permissions_count, 'total' => $totalPermissions]) }}
                            </td>
                            <td>{{ $userCounts[$role->id] ?? 0 }}</td>
                            <td class="text-end text-nowrap">
                                @can('update', $role)
                                    <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-light"><x-icon name="pencil" size="14" /> {{ __('Edit') }}</a>
                                @else
                                    <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-light"><x-icon name="eye" size="14" /> {{ __('View') }}</a>
                                @endcan
                                @if (! $system && auth()->user()->can('delete', $role))
                                    <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $role->id }})"
                                            wire:confirm="{{ __('Delete the :name role?', ['name' => $role->name]) }}"
                                            aria-label="{{ __('Delete :name', ['name' => $role->name]) }}">
                                        <x-icon name="trash-2" size="14" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

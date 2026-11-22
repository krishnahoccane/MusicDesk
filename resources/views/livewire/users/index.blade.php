<div>
    <x-page-header :title="__('Users')" :subtitle="__('People who can sign in to :name and what they can do.', ['name' => config('app.name')])">
        @can('create', App\Models\User::class)
            <x-slot:actions>
                @if ($seatsAvailable)
                    <a href="{{ route('users.create') }}" class="btn btn-primary">
                        <x-icon name="user-plus" size="16" /> {{ __('Add user') }}
                    </a>
                @else
                    <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="{{ __('Your license user allowance is used up.') }}">
                        <button type="button" class="btn btn-primary" disabled>
                            <x-icon name="user-plus" size="16" /> {{ __('Add user') }}
                        </button>
                    </span>
                @endif
            </x-slot:actions>
        @endcan
    </x-page-header>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 small">
        <x-badge :variant="$seatsAvailable ? 'success' : 'warning'" icon="users">
            @if ($seatLimit === null)
                {{ trans_choice('{1} :count active user|[0,*] :count active users', $activeCount, ['count' => $activeCount]) }} &middot; {{ __('unlimited allowance') }}
            @else
                {{ __(':count of :limit active users', ['count' => $activeCount, 'limit' => $seatLimit]) }}
            @endif
        </x-badge>
        @if ($seatLimit !== null && $activeCount > $seatLimit)
            <span class="text-warning">{{ __('More users are active than your plan allows. Existing users keep working, but no one can be added or reactivated until the count is within the allowance.') }}</span>
        @endif
    </div>

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-icon">
                        <x-icon name="search" size="16" />
                        <input type="search" class="form-control" placeholder="{{ __('Search name or email') }}"
                               wire:model.live.debounce.300ms="search" aria-label="{{ __('Search users') }}" maxlength="100">
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <select class="form-select" wire:model.live="role" aria-label="{{ __('Filter by role') }}">
                        <option value="">{{ __('All roles') }}</option>
                        @foreach ($roles as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Deactivated') }}</option>
                        <option value="deleted">{{ __('Deleted') }}</option>
                    </select>
                </div>
                <div class="col-md-2 text-md-end">
                    @if ($search !== '' || $role !== '' || $status !== '')
                        <button type="button" class="btn btn-light" wire:click="clearFilters">{{ __('Clear') }}</button>
                    @endif
                </div>
            </div>
        </div>

        @if ($users->isEmpty())
            <x-empty-state icon="users" :title="__('No users found')" :description="__('Try a different search or filter.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('User') }}</th>
                            <th>{{ __('Role') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('Last sign-in') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr wire:key="user-{{ $user->id }}">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="ld-avatar">{{ $user->initials }}</span>
                                        <div class="lh-sm">
                                            <div class="fw-semibold">
                                                {{ $user->name }}
                                                @if ($user->is(auth()->user()))
                                                    <span class="small text-muted fw-normal">({{ __('you') }})</span>
                                                @endif
                                            </div>
                                            <div class="small text-muted">{{ $user->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                                <td>
                                    @if ($user->trashed())
                                        <x-badge variant="danger">{{ __('Deleted') }}</x-badge>
                                    @elseif ($user->is_active)
                                        <x-badge variant="success">{{ __('Active') }}</x-badge>
                                    @else
                                        <x-badge variant="secondary">{{ __('Deactivated') }}</x-badge>
                                    @endif
                                </td>
                                <td class="d-none d-lg-table-cell small text-muted">{{ App\Support\Format::datetime($user->last_login_at, __('Never')) }}</td>
                                <td class="text-end">
                                    @if ($user->trashed())
                                        @can('restore', $user)
                                            <button type="button" class="btn btn-sm btn-light" wire:click="restore({{ $user->id }})"
                                                    wire:confirm="{{ __('Restore :name? The account comes back deactivated.', ['name' => $user->name]) }}">
                                                <x-icon name="rotate-ccw" size="14" /> {{ __('Restore') }}
                                            </button>
                                        @endcan
                                    @elseif (auth()->user()->can('update', $user))
                                        <div class="dropdown">
                                            <button type="button" class="ld-icon-btn ms-auto" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Actions for :name', ['name' => $user->name]) }}">
                                                <x-icon name="ellipsis-vertical" size="18" />
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="{{ route('users.edit', $user) }}"><x-icon name="pencil" size="16" /> {{ __('Edit') }}</a></li>
                                                @if ($user->is_active)
                                                    <li><button type="button" class="dropdown-item" wire:click="sendPasswordLink({{ $user->id }})"><x-icon name="mail" size="16" /> {{ __('Send password link') }}</button></li>
                                                @endif
                                                @if ($user->isNot(auth()->user()))
                                                    <li><hr class="dropdown-divider"></li>
                                                    @if ($user->is_active)
                                                        <li><button type="button" class="dropdown-item" wire:click="toggleActive({{ $user->id }})"
                                                                    wire:confirm="{{ __('Deactivate :name? They will be signed out immediately.', ['name' => $user->name]) }}"><x-icon name="user-x" size="16" /> {{ __('Deactivate') }}</button></li>
                                                    @else
                                                        <li><button type="button" class="dropdown-item" wire:click="toggleActive({{ $user->id }})"><x-icon name="user-check" size="16" /> {{ __('Activate') }}</button></li>
                                                    @endif
                                                    <li><button type="button" class="dropdown-item text-danger" wire:click="delete({{ $user->id }})"
                                                                wire:confirm="{{ __('Delete :name? They lose access immediately. Their history is kept and the account can be restored.', ['name' => $user->name]) }}"><x-icon name="trash-2" size="16" /> {{ __('Delete') }}</button></li>
                                                @endif
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($users->hasPages())
                <div class="card-body border-top py-2">{{ $users->links() }}</div>
            @endif
        @endif
    </div>
</div>

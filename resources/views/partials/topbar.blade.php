@php($user = auth()->user())
<header class="ld-topbar">
    <button type="button" class="ld-icon-btn" data-sidebar-toggle aria-label="{{ __('Toggle navigation') }}">
        <x-icon name="panel-left" size="20" />
    </button>

    <livewire:global-search />

    <div class="ld-topbar__actions">
        @if ($appearance['toggle'] ?? true)
            <div class="dropdown">
                <button type="button" class="ld-icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Change colour mode') }}">
                    <x-icon name="sun" size="19" class="ld-theme-icon--light" />
                    <x-icon name="moon" size="19" class="ld-theme-icon--dark" />
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @foreach (\App\Enums\ThemePreference::cases() as $theme)
                        <li>
                            <button type="button" class="dropdown-item" data-theme-option="{{ $theme->value }}">
                                <x-icon :name="$theme->icon()" size="16" />
                                {{ $theme->label() }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="dropdown ms-1">
            <button type="button" class="btn btn-link p-1 d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Account menu') }}">
                <span class="ld-avatar">{{ $user->initials }}</span>
                <x-icon name="chevron-down" size="16" class="text-muted d-none d-sm-inline-block" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 240px">
                <li class="px-2 py-2 d-flex align-items-center gap-2">
                    <span class="ld-avatar">{{ $user->initials }}</span>
                    <span class="min-w-0">
                        <span class="d-block small fw-semibold text-truncate" style="color: var(--ld-heading)">{{ $user->name }}</span>
                        <span class="d-block small text-muted text-truncate">{{ $user->email }}</span>
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a href="{{ route('profile') }}" class="dropdown-item">
                        <x-icon name="user-round" size="16" /> {{ __('My profile') }}
                    </a>
                </li>
                @can('settings.manage')
                    <li>
                        <a href="{{ route('settings.appearance') }}" class="dropdown-item">
                            <x-icon name="palette" size="16" /> {{ __('Appearance') }}
                        </a>
                    </li>
                @endcan
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <x-icon name="log-out" size="16" /> {{ __('Sign out') }}
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>

@php($user = auth()->user())
<aside class="ld-sidebar" aria-label="{{ __('Main navigation') }}">
    <a href="{{ route('dashboard') }}" class="ld-sidebar__brand">
        <x-logo size="34" />
        <span class="ld-sidebar__brand-text">
            <strong>{{ config('app.name') }}</strong>
        </span>
    </a>

    <nav class="ld-sidebar__nav">
        @foreach ($navigation as $group)
            <div class="ld-nav-group">
                @if ($group['label'])
                    <div class="ld-nav-group__label">{{ __($group['label']) }}</div>
                @endif

                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}"
                       @class(['ld-nav-link', 'active' => $item['is_active']])
                       @if ($item['is_active']) aria-current="page" @endif
                       title="{{ __($item['label']) }}">
                        <x-icon :name="$item['icon']" size="18" />
                        <span class="ld-nav-link__text">{{ __($item['label']) }}</span>
                        @if ($item['locked'])
                            <x-icon name="lock" size="14" class="ld-nav-link__badge" aria-label="{{ __('Not included in your plan') }}" />
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    <a href="{{ route('profile') }}" class="ld-sidebar__user" title="{{ __('My profile') }}">
        <span class="ld-avatar">{{ $user->initials }}</span>
        <span class="ld-sidebar__user-text">
            <strong>{{ $user->name }}</strong>
            <small>{{ $user->getRoleNames()->first() ?? __('User') }}</small>
        </span>
    </a>
</aside>

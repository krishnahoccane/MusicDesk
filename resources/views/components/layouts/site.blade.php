@props(['title' => null, 'settings' => [], 'description' => null, 'image' => null])
@php
    $ldName = config('app.name');
    $ldDescription = $description ?: ($settings['meta_description'] ?? '') ?: ($settings['hero_text'] ?? '');
    $ldSocials = $settings['socials'] ?? [];
    $ldSocialIcons = ['instagram' => 'instagram', 'youtube' => 'youtube', 'facebook' => 'facebook', 'x' => 'twitter'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="ld-no-transition"
      data-theme-preference="{{ $themePreference }}"
      @unless ($appearance['toggle']) data-theme-locked="1" @endunless
      data-ld-theme="{{ $appearance['theme'] }}"
      data-ld-font="{{ $appearance['font'] }}"
      data-bs-theme="light">
<head>
    @include('partials.head', ['title' => $title, 'robots' => 'index, follow', 'description' => $ldDescription ?: null])
    <meta property="og:site_name" content="{{ $ldName }}">
    <meta property="og:title" content="{{ $title ? $title.' · '.$ldName : $ldName }}">
    @if ($ldDescription) <meta property="og:description" content="{{ $ldDescription }}"> @endif
    @if ($image) <meta property="og:image" content="{{ $image }}"> @endif
</head>
<body class="ld-site">
    <a href="#main" class="visually-hidden-focusable ld-site__skip">{{ __('Skip to content') }}</a>

    <header class="ld-site__header" x-data="{ open: false }">
        <div class="ld-site__container ld-site__nav">
            <a href="{{ route('home') }}" class="ld-site__brand" aria-label="{{ $ldName }}">
                <x-logo size="34" />
                <span>{{ $ldName }}</span>
            </a>
            <button type="button" class="ld-site__menu-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="ld-site-menu" aria-label="{{ __('Menu') }}">
                <x-icon name="menu" size="22" />
            </button>
            <nav id="ld-site-menu" class="ld-site__menu" x-bind:class="{ 'is-open': open }" aria-label="{{ __('Website') }}">
                @if ($settings['show_releases'] ?? true)
                    <a href="{{ route('site.music') }}" @class(['active' => request()->routeIs('site.music', 'site.release')])>{{ __('Music') }}</a>
                @endif
                @if ($settings['show_artists'] ?? true)
                    <a href="{{ route('site.roster') }}" @class(['active' => request()->routeIs('site.roster', 'site.artist')])>{{ __('Artists') }}</a>
                @endif
                @if ($settings['show_about'] ?? true)
                    <a href="{{ route('home') }}#about">{{ __('About') }}</a>
                @endif
                @if ($settings['show_contact'] ?? true)
                    <a href="{{ route('home') }}#contact">{{ __('Contact') }}</a>
                @endif
                @if ($appearance['toggle'])
                    {{-- The app's theme script reads data-theme-option on click; set it to the other mode first. --}}
                    <button type="button" class="ld-site__icon-btn" data-theme-option="dark"
                            x-on:click="$el.dataset.themeOption = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark'"
                            aria-label="{{ __('Switch light / dark') }}"><x-icon name="sun-moon" size="18" /></button>
                @endif
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-primary">{{ __('Dashboard') }}</a>
                @elseif ($settings['show_login_link'] ?? true)
                    <a href="{{ route('login') }}" class="btn btn-sm btn-light"><x-icon name="log-in" size="14" /> {{ __('Sign in') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="ld-site__footer">
        <div class="ld-site__container">
            <div class="ld-site__footer-grid">
                <div>
                    <a href="{{ route('home') }}" class="ld-site__brand"><x-logo size="30" /> <span>{{ $ldName }}</span></a>
                    @if ($settings['hero_text'] ?? '')
                        <p class="ld-site__muted mt-3 mb-0">{{ $settings['hero_text'] }}</p>
                    @endif
                </div>
                @if ($ldSocials)
                    <div>
                        <div class="ld-site__eyebrow">{{ __('Follow') }}</div>
                        <ul class="ld-site__socials">
                            @foreach ($ldSocials as $network => $url)
                                <li><a href="{{ $url }}" target="_blank" rel="noopener me">
                                    <x-icon :name="$ldSocialIcons[$network] ?? 'music-2'" size="16" />
                                    {{ App\Services\Website\WebsiteContent::SOCIALS[$network] ?? $network }}
                                </a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (($settings['show_contact'] ?? true) && (($settings['contact_email'] ?? '') || ($settings['contact_phone'] ?? '')))
                    <div>
                        <div class="ld-site__eyebrow">{{ __('Contact') }}</div>
                        @if ($settings['contact_email']) <a class="d-block mb-1" href="mailto:{{ $settings['contact_email'] }}">{{ $settings['contact_email'] }}</a> @endif
                        @if ($settings['contact_phone']) <a class="d-block" href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['contact_phone']) }}">{{ $settings['contact_phone'] }}</a> @endif
                    </div>
                @endif
            </div>
            <div class="ld-site__legal">&copy; {{ date('Y') }} {{ $ldName }}</div>
        </div>
    </footer>
</body>
</html>

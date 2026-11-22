@props([
    'title' => null,
    'breadcrumbs' => [],
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="ld-no-transition"
      data-theme-preference="{{ $themePreference }}"
      @if ($appearance['toggle']) data-theme-endpoint="{{ route('preferences.theme') }}" @else data-theme-locked="1" @endif
      data-ld-theme="{{ $appearance['theme'] }}"
      data-ld-sidebar="{{ $appearance['sidebar'] }}"
      data-bs-theme="light"
      data-sidebar="expanded"
      data-sidebar-mobile="closed">
<head>
    @include('partials.head', ['title' => $title])
    @livewireStyles
</head>
<body>
    <a href="#main-content" class="visually-hidden-focusable">{{ __('Skip to content') }}</a>

    <div class="ld-shell">
        @include('partials.sidebar')
        <div class="ld-backdrop" data-sidebar-close></div>

        <div class="ld-main">
            @include('partials.topbar')

            <main id="main-content" class="ld-content" tabindex="-1">
                @if (count($breadcrumbs))
                    <x-breadcrumbs :items="$breadcrumbs" class="mb-2" />
                @endif
                @if (isset($licenseSnapshot) && $licenseSnapshot->state !== \App\Enums\LicenseState::Active)
                    <x-alert :variant="$licenseSnapshot->state->variant()" class="mb-4" :title="$licenseSnapshot->state->label()">
                        {{ $licenseSnapshot->reason }}
                        @can('license.view')
                            <a href="{{ route('license.show') }}" class="fw-semibold ms-1">{{ __('License information') }} &rarr;</a>
                        @endcan
                    </x-alert>
                @endif
                {{ $slot }}
            </main>

            <footer class="ld-footer d-flex flex-wrap justify-content-between gap-2">
                <span>&copy; {{ now()->year }} {{ config('app.name') }}</span>
                <span>{{ config('labeldesk.product.name') }} {{ config('labeldesk.product.version') }}</span>
            </footer>
        </div>
    </div>

    @include('partials.flash')
    @livewireScripts
</body>
</html>

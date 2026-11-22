@props(['title' => null, 'step' => 'welcome', 'wide' => false])
@php
    // Numbered steps (Welcome and Install bracket them, unnumbered).
    $steps = [
        'license' => __('License'),
        'requirements' => __('Server check'),
        'database' => __('Database'),
        'redis' => __('Redis'),
        'mail' => __('Email'),
        'administrator' => __('Super admin'),
        'customize' => __('Customise'),
    ];
    $order = ['welcome', ...array_keys($steps), 'install', 'complete'];
    $current = array_search($step, $order, true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="ld-no-transition"
      data-theme-preference="{{ $themePreference ?? 'dark' }}"
      data-ld-theme="{{ $appearance['theme'] ?? 'musicdesk' }}"
      data-ld-font="{{ $appearance['font'] ?? 'inter-tight' }}"
      data-bs-theme="dark">
<head>
    @include('partials.head', ['title' => $title ? $title.' · '.__('Setup') : __('Setup')])
    @livewireStyles
</head>
<body class="ld-installer">
    <div class="ld-installer__glow" aria-hidden="true"></div>
    <div class="grid-bg ld-installer__grid" aria-hidden="true"></div>

    <div class="ld-installer__wrap">
        <header class="ld-installer__header">
            <div class="d-flex align-items-center gap-2 fw-semibold" style="color: var(--ld-heading); letter-spacing: -0.02em">
                <x-logo size="32" mark />
                <span class="fs-5">{{ config('labeldesk.product.name') }}</span>
                <span class="ld-badge ld-badge--accent ms-1">{{ __('Setup') }}</span>
            </div>
            <span class="eyebrow d-none d-sm-inline">v{{ config('labeldesk.product.version') }}</span>
        </header>

        @if (! in_array($step, ['welcome', 'complete'], true))
            <nav class="ld-stepper" aria-label="{{ __('Installation steps') }}">
                <ol>
                    @foreach ($steps as $key => $label)
                        @php($index = array_search($key, $order, true))
                        <li @class(['is-done' => $index < $current, 'is-current' => $index === $current]) @if ($index === $current) aria-current="step" @endif>
                            <span class="ld-stepper__dot">
                                @if ($index < $current)
                                    <x-icon name="check" size="13" stroke="3" />
                                @else
                                    {{ $loop->index }}
                                @endif
                            </span>
                            <span class="ld-stepper__label">{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        <main @class(['card ld-installer__card', 'ld-installer__card--wide' => $wide])>
            <div class="card-body p-4 p-md-5">
                @if (session('success'))
                    <x-alert variant="success" class="mb-4">{{ session('success') }}</x-alert>
                @endif
                @if (session('error'))
                    <x-alert variant="danger" class="mb-4">{{ session('error') }}</x-alert>
                @endif

                {{ $slot }}
            </div>
        </main>

        <footer class="text-center small py-4" style="color: var(--ld-text-subtle)">
            &copy; {{ now()->year }} MusicDesk &middot; <a href="https://musicdesk.tech" target="_blank" rel="noopener">musicdesk.tech</a>
        </footer>
    </div>
    @livewireScripts {{-- provides Alpine for the live previews --}}
</body>
</html>

@props(['title' => null, 'step' => 'welcome'])
@php
    $steps = [
        'welcome' => __('Welcome'),
        'requirements' => __('Requirements'),
        'database' => __('Database'),
        'application' => __('Application'),
        'administrator' => __('Administrator'),
        'license' => __('License'),
        'install' => __('Install'),
        'complete' => __('Complete'),
    ];
    $keys = array_keys($steps);
    $current = array_search($step, $keys, true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="ld-no-transition" data-theme-preference="system" data-ld-theme="{{ $appearance['theme'] ?? 'aurora' }}" data-bs-theme="light">
<head>
    @include('partials.head', ['title' => $title ? $title.' · '.__('Installation') : __('Installation')])
</head>
<body class="ld-installer">
    <div class="ld-installer__wrap">
        <header class="ld-installer__header">
            <div class="d-flex align-items-center gap-2 fw-bold fs-5" style="color: var(--ld-heading)">
                <x-logo size="34" />
                {{ config('labeldesk.product.name') }}
                <span class="ld-badge ms-1">{{ __('Setup') }}</span>
            </div>
            <span class="small text-muted d-none d-sm-inline">v{{ config('labeldesk.product.version') }}</span>
        </header>

        <div class="ld-installer__grid">
            <nav class="ld-installer__steps" aria-label="{{ __('Installation steps') }}">
                <ol>
                    @foreach ($steps as $key => $label)
                        @php($index = $loop->index)
                        <li @class([
                            'is-done' => $index < $current,
                            'is-current' => $index === $current,
                        ]) @if ($index === $current) aria-current="step" @endif>
                            <span class="ld-installer__dot">
                                @if ($index < $current)
                                    <x-icon name="check" size="14" stroke="3" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
                            <span>{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>
            </nav>

            <main class="card ld-installer__card">
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
        </div>

        <footer class="text-center small text-subtle py-4">
            &copy; {{ now()->year }} MusicDesk &middot; <a href="https://musicdesk.tech" target="_blank" rel="noopener">musicdesk.tech</a>
        </footer>
    </div>
</body>
</html>

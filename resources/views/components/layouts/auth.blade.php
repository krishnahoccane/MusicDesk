@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="ld-no-transition"
      data-theme-preference="{{ $themePreference }}"
      @unless ($appearance['toggle']) data-theme-locked="1" @endunless
      data-ld-theme="{{ $appearance['theme'] }}"
      data-bs-theme="light">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body>
    <div class="ld-auth">
        <section class="ld-auth__brand" aria-hidden="true">
            <div class="d-flex align-items-center gap-2 fw-bold fs-5">
                <x-logo size="36" />
                {{ config('labeldesk.product.name') }}
            </div>

            <div>
                <div class="ld-auth__wave">
                    @foreach ([28, 52, 36, 64, 44, 72, 30, 58, 40, 66, 34, 50, 24, 46] as $h)
                        <span style="height: {{ $h }}px"></span>
                    @endforeach
                </div>
                <h2>{{ __('Run your label from a single, focused workspace.') }}</h2>
                <p class="mt-3 mb-0">{{ __('Artists, releases, distribution and royalties — organised, auditable and under your control.') }}</p>
            </div>

            <div class="small" style="color: rgba(255,255,255,.5)">
                {{ config('app.name') }} &middot; {{ config('labeldesk.product.name') }} {{ config('labeldesk.product.version') }}
            </div>
        </section>

        <main class="ld-auth__panel">
            <div class="ld-auth__card">
                <div class="d-flex d-lg-none align-items-center gap-2 fw-bold fs-5 mb-4" style="color: var(--ld-heading)">
                    <x-logo size="32" />
                    {{ config('labeldesk.product.name') }}
                </div>
                {{ $slot }}
            </div>
        </main>
    </div>

    @include('partials.flash')
</body>
</html>

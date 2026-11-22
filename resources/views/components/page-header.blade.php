@props(['title', 'subtitle' => null, 'eyebrow' => null])
@php
    // Eyebrow ("Catalog · Releases") from the navigation section of the current page.
    if ($eyebrow === null) {
        foreach ((array) config('navigation', []) as $ldGroup) {
            foreach ($ldGroup['items'] ?? [] as $ldItem) {
                if (request()->routeIs($ldItem['active'] ?? $ldItem['route'])) {
                    $eyebrow = trim(($ldGroup['label'] ? __($ldGroup['label']).' · ' : '').__($ldItem['label']), ' ·');
                    break 2;
                }
            }
        }
    }
@endphp
<div {{ $attributes->merge(['class' => 'ld-page-header']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <div class="ld-page-header__eyebrow mb-2">{{ $eyebrow }}</div>
        @endif
        <h1 class="ld-page-header__title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="ld-page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="d-flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>

@props(['size' => 32, 'mark' => false])
@php
    $ldLogo = $mark ? null : App\Support\Branding::url('logo');
    $ldLogoDark = $mark ? null : (App\Support\Branding::url('logo_dark') ?? $ldLogo);
    $gid = 'ld-logo-'.\Illuminate\Support\Str::random(6);
@endphp
@if ($ldLogo)
    {{-- Uploaded logo (Settings → Appearance); the dark-mode version is optional. --}}
    <span {{ $attributes->merge(['class' => 'ld-logo-image']) }} style="height: {{ (int) $size }}px">
        <img src="{{ $ldLogo }}" alt="" class="ld-logo-image__light" style="height: {{ (int) $size }}px">
        <img src="{{ $ldLogoDark }}" alt="" class="ld-logo-image__dark" style="height: {{ (int) $size }}px">
    </span>
@else
<svg {{ $attributes->merge(['class' => 'ld-logo-mark']) }} width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
            <stop style="stop-color: var(--ld-accent, #7c5cff)"/>
            <stop offset="1" style="stop-color: var(--ld-accent-2, #ff3fd2)"/>
        </linearGradient>
    </defs>
    <rect width="32" height="32" rx="9" fill="url(#{{ $gid }})"/>
    <rect x="7" y="13" width="3" height="9" rx="1.5" fill="#fff"/>
    <rect x="12.5" y="8" width="3" height="16" rx="1.5" fill="#fff"/>
    <rect x="18" y="11" width="3" height="11" rx="1.5" fill="#fff" fill-opacity=".85"/>
    <rect x="23.5" y="15" width="3" height="5" rx="1.5" fill="#fff" fill-opacity=".7"/>
</svg>
@endif

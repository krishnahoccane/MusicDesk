@props(['variant' => 'info', 'title' => null])
@php
    $icon = match ($variant) {
        'success' => 'circle-check-big',
        'warning' => 'triangle-alert',
        'danger' => 'circle-x',
        default => 'info',
    };
@endphp
<div {{ $attributes->class(['ld-alert', 'ld-alert--'.$variant]) }} role="{{ $variant === 'danger' ? 'alert' : 'status' }}">
    <x-icon :name="$icon" size="18" class="mt-1" />
    <div class="ld-alert__body">
        @if ($title)
            <div class="fw-semibold mb-1">{{ $title }}</div>
        @endif
        {{ $slot }}
    </div>
</div>

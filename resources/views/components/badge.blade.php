@props(['variant' => null, 'icon' => null])
<span {{ $attributes->class(['ld-badge', 'ld-badge--'.$variant => $variant]) }}>
    @if ($icon)
        <x-icon :name="$icon" size="12" />
    @endif
    {{ $slot }}
</span>

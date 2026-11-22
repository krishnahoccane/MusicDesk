@props([
    'label',
    'value',
    'icon' => 'activity',
    'variant' => null, // success | warning | danger | info
    'hint' => null,
    'href' => null,
])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card ld-stat text-reset']) }}>
    <span @class(['ld-stat__icon', 'ld-stat__icon--'.$variant => $variant])>
        <x-icon :name="$icon" size="20" />
    </span>
    <div class="min-w-0">
        <div class="ld-stat__label">{{ $label }}</div>
        <div class="ld-stat__value">{{ $value }}</div>
        @if ($hint)
            <div class="ld-stat__hint">{{ $hint }}</div>
        @endif
    </div>
</{{ $tag }}>

@props(['title', 'subtitle' => null])
<div {{ $attributes->merge(['class' => 'ld-page-header']) }}>
    <div>
        <h1 class="ld-page-header__title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="ld-page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="d-flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>

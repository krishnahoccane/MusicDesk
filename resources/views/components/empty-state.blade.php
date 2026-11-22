@props(['icon' => 'inbox', 'title', 'description' => null])
<div {{ $attributes->merge(['class' => 'ld-empty']) }}>
    <div class="ld-empty__icon"><x-icon :name="$icon" size="24" /></div>
    <h3>{{ $title }}</h3>
    @if ($description)
        <p class="mb-3">{{ $description }}</p>
    @endif
    {{ $slot }}
</div>

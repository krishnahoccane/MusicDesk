@props(['release', 'size' => 48, 'full' => false])
@if ($release->artwork_path)
    <img src="{{ route('releases.artwork', ['release' => $release, 'size' => $full ? 'full' : 'thumb', 'v' => substr(md5((string) $release->artwork_path), 0, 8)]) }}"
         alt="" width="{{ $size }}" height="{{ $size }}" loading="lazy"
         {{ $attributes->class(['ld-artwork']) }} style="width: {{ $size }}px; height: {{ $size }}px">
@else
    <span {{ $attributes->class(['ld-artwork ld-artwork--empty']) }} style="width: {{ $size }}px; height: {{ $size }}px" aria-hidden="true">
        <x-icon name="disc-3" :size="max(16, (int) ($size * 0.4))" />
    </span>
@endif

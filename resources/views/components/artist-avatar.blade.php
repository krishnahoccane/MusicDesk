@props(['artist', 'size' => 40])
@if ($artist->photo_path)
    <img src="{{ route('artists.photo', ['artist' => $artist, 'v' => substr(md5((string) $artist->photo_path), 0, 8)]) }}"
         alt="" width="{{ $size }}" height="{{ $size }}" loading="lazy"
         {{ $attributes->class(['ld-artist-avatar']) }} style="width: {{ $size }}px; height: {{ $size }}px">
@else
    <span {{ $attributes->class(['ld-artist-avatar ld-artist-avatar--initials']) }}
          style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ max(11, (int) ($size * 0.36)) }}px" aria-hidden="true">{{ $artist->initials }}</span>
@endif

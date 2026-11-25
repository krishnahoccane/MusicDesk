{{-- Needs: $artist --}}
<a href="{{ route('site.artist', App\Services\Website\WebsiteContent::artistSlug($artist)) }}" class="ld-site-artist">
    <span class="ld-site-artist__photo">
        @if ($artist->photo_path)
            <img src="{{ route('site.photo', $artist->id) }}" alt="{{ $artist->name }}" loading="lazy" width="400" height="400">
        @else
            <span aria-hidden="true">{{ \Illuminate\Support\Str::of($artist->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
        @endif
    </span>
    <span class="ld-site-artist__name">{{ $artist->name }}</span>
    @if ($artist->primary_genre)
        <span class="ld-site-artist__genre">{{ $artist->primary_genre }}</span>
    @endif
</a>

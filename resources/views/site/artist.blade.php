@php
    $ldPhoto = $artist->photo_path ? route('site.photo', $artist->id) : null;
    $ldBio = collect(preg_split("/\R\s*\R/", trim((string) $artist->bio)))->filter()->values();
    $ldNow = now()->toDateString();
@endphp
<x-layouts.site :title="$artist->name" :settings="$settings" :image="$ldPhoto"
                :description="$ldBio->first() ? \Illuminate\Support\Str::limit($ldBio->first(), 155) : null">
    <section class="ld-site-artist-head">
        <div class="ld-site__container ld-site-artist-head__grid">
            <div class="ld-site-artist-head__photo">
                @if ($ldPhoto)
                    <img src="{{ $ldPhoto }}" alt="{{ $artist->name }}" width="600" height="600">
                @else
                    <span aria-hidden="true">{{ \Illuminate\Support\Str::of($artist->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
                @endif
            </div>
            <div>
                <a href="{{ route('site.roster') }}" class="ld-site__back"><x-icon name="arrow-left" size="16" /> {{ __('All artists') }}</a>
                <div class="ld-site__eyebrow">{{ $artist->type->label() }}@if ($artist->primary_genre) · {{ $artist->primary_genre }}@endif</div>
                <h1 class="ld-site-release__title">{{ $artist->name }}</h1>
                @foreach ($ldBio as $paragraph)
                    <p class="ld-site-artist-head__bio">{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
                @if ($links->isNotEmpty())
                    <ul class="ld-site__socials ld-site__socials--inline">
                        @foreach ($links as $link)
                            <li><a href="{{ $link->url }}" target="_blank" rel="noopener nofollow"><x-icon :name="$link->platform->icon()" size="16" /> {{ $link->platform->label() }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>

    @if ($releases->isNotEmpty())
        <section class="ld-site-section ld-site-section--tight">
            <div class="ld-site__container">
                <div class="ld-site-section__head"><h2>{{ __('Releases') }}</h2></div>
                <div class="ld-site-grid ld-site-grid--releases">
                    @foreach ($releases as $release)
                        @include('site.partials.release-card', ['release' => $release, 'upcoming' => $release->release_date->toDateString() > $ldNow])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.site>

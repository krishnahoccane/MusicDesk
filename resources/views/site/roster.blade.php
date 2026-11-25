<x-layouts.site :title="__('Artists')" :settings="$settings">
    <section class="ld-site-page-head">
        <div class="ld-site__container">
            <div class="ld-site__eyebrow">{{ config('app.name') }}</div>
            <h1>{{ __('Artists') }}</h1>
        </div>
    </section>
    <section class="ld-site-section ld-site-section--tight">
        <div class="ld-site__container">
            @if ($artists->isEmpty())
                <p class="ld-site__muted">{{ __('Our roster will appear here soon.') }}</p>
            @else
                <div class="ld-site-grid ld-site-grid--artists">
                    @foreach ($artists as $artist)
                        @include('site.partials.artist-card', ['artist' => $artist])
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</x-layouts.site>

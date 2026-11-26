<x-layouts.site :title="__('Music')" :settings="$settings">
    <section class="ld-site-page-head">
        <div class="ld-site__container">
            <div class="ld-site__eyebrow">{{ config('app.name') }}</div>
            <h1>{{ __('Music') }}</h1>
        </div>
    </section>

    @if ($upcoming->isNotEmpty())
        <section class="ld-site-section ld-site-section--tight">
            <div class="ld-site__container">
                <div class="ld-site-section__head"><h2>{{ __('Coming soon') }}</h2></div>
                <div class="ld-site-grid ld-site-grid--releases">
                    @foreach ($upcoming as $release)
                        @include('site.partials.release-card', ['release' => $release, 'isUpcoming' => true])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="ld-site-section ld-site-section--tight">
        <div class="ld-site__container">
            @if ($upcoming->isNotEmpty())
                <div class="ld-site-section__head"><h2>{{ __('Out now') }}</h2></div>
            @endif
            @if ($releases->isEmpty())
                <p class="ld-site__muted">{{ __('New music is on its way. Check back soon.') }}</p>
            @else
                <div class="ld-site-grid ld-site-grid--releases">
                    @foreach ($releases as $release)
                        @include('site.partials.release-card', ['release' => $release, 'isUpcoming' => false])
                    @endforeach
                </div>
                <div class="mt-5">{{ $releases->links() }}</div>
            @endif
        </div>
    </section>
</x-layouts.site>

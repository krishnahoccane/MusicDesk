@php
    $ldHeroImage = ($settings['hero_use_login_image'] ?? false) ? App\Support\Branding::url('login_image') : null;
    $ldParagraphs = collect(preg_split("/\R\s*\R/", trim((string) ($settings['about_text'] ?? ''))))->filter()->values();
@endphp
<x-layouts.site :settings="$settings" :image="$featured?->artwork_path ? route('site.artwork', [$featured->id, 'full']) : null">
    {{-- Hero --}}
    <section @class(['ld-site-hero', 'ld-site-hero--image' => $ldHeroImage])
             @if ($ldHeroImage) style="--ld-site-hero-image: url('{{ $ldHeroImage }}')" @endif>
        <div class="ld-site-hero__glow ld-site-hero__glow--a" aria-hidden="true"></div>
        <div class="ld-site-hero__glow ld-site-hero__glow--b" aria-hidden="true"></div>
        <div class="ld-site__container ld-site-hero__grid">
            <div class="ld-site-hero__copy">
                <div class="ld-site__eyebrow">{{ __('Record label') }}</div>
                <h1 class="ld-site-hero__title">{{ $settings['hero_title'] ?: config('app.name') }}</h1>
                @if ($settings['hero_text'])
                    <p class="ld-site-hero__text">{{ $settings['hero_text'] }}</p>
                @endif
                <div class="ld-site-hero__actions">
                    @if ($settings['show_releases'] && $releaseCount > 0)
                        <a href="{{ route('site.music') }}" class="btn btn-primary btn-lg">{{ __('Explore the music') }} <x-icon name="arrow-right" size="18" /></a>
                    @endif
                    @if ($settings['show_artists'] && $artistCount > 0)
                        <a href="{{ route('site.roster') }}" class="btn btn-light btn-lg">{{ __('Meet the artists') }}</a>
                    @endif
                </div>
                @if ($releaseCount > 0 || $artistCount > 0)
                    <dl class="ld-site-hero__stats">
                        @if ($releaseCount > 0) <div><dt>{{ $releaseCount }}</dt><dd>{{ trans_choice('release|releases', $releaseCount) }}</dd></div> @endif
                        @if ($artistCount > 0) <div><dt>{{ $artistCount }}</dt><dd>{{ trans_choice('artist|artists', $artistCount) }}</dd></div> @endif
                    </dl>
                @endif
            </div>
            @if ($featured)
                @php($ldFeaturedUpcoming = $featured->release_date?->isFuture() ?? false)
                <a href="{{ route('site.release', App\Services\Website\WebsiteContent::releaseSlug($featured)) }}" class="ld-site-feature">
                    <span class="ld-site-feature__art">
                        @if ($featured->artwork_path)
                            <img src="{{ route('site.artwork', [$featured->id, 'full']) }}" alt="{{ __('Cover of :title', ['title' => $featured->displayTitle()]) }}" width="900" height="900">
                        @else
                            <span class="ld-site-card__placeholder" aria-hidden="true"><x-icon name="disc-3" size="64" /></span>
                        @endif
                    </span>
                    <span class="ld-site-feature__caption">
                        <span class="ld-site__eyebrow">{{ $ldFeaturedUpcoming ? __('Coming :date', ['date' => $featured->release_date->format('j M Y')]) : __('Featured release') }}</span>
                        <span class="ld-site-feature__title">{{ $featured->displayTitle() }}</span>
                        <span class="ld-site__muted">{{ $featured->artistLine() }}</span>
                    </span>
                </a>
            @endif
        </div>
    </section>

    {{-- Latest and upcoming releases --}}
    @if ($settings['show_releases'] && ($latest->isNotEmpty() || $upcoming->isNotEmpty()))
        <section class="ld-site-section" id="music">
            <div class="ld-site__container">
                @if ($upcoming->isNotEmpty())
                    <div class="ld-site-section__head">
                        <h2>{{ __('Coming soon') }}</h2>
                    </div>
                    <div class="ld-site-grid ld-site-grid--releases mb-5">
                        @foreach ($upcoming as $release)
                            @include('site.partials.release-card', ['release' => $release, 'upcoming' => true])
                        @endforeach
                    </div>
                @endif
                @if ($latest->isNotEmpty())
                    <div class="ld-site-section__head">
                        <h2>{{ __('Latest releases') }}</h2>
                        <a href="{{ route('site.music') }}" class="ld-site__more">{{ __('All music') }} <x-icon name="arrow-right" size="16" /></a>
                    </div>
                    <div class="ld-site-grid ld-site-grid--releases">
                        @foreach ($latest as $release)
                            @include('site.partials.release-card', ['release' => $release])
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Artists --}}
    @if ($settings['show_artists'] && $artists->isNotEmpty())
        <section class="ld-site-section ld-site-section--tinted" id="artists">
            <div class="ld-site__container">
                <div class="ld-site-section__head">
                    <h2>{{ __('Our artists') }}</h2>
                    <a href="{{ route('site.roster') }}" class="ld-site__more">{{ __('All artists') }} <x-icon name="arrow-right" size="16" /></a>
                </div>
                <div class="ld-site-grid ld-site-grid--artists">
                    @foreach ($artists as $artist)
                        @include('site.partials.artist-card', ['artist' => $artist])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- About --}}
    @if ($settings['show_about'] && $ldParagraphs->isNotEmpty())
        <section class="ld-site-section" id="about">
            <div class="ld-site__container ld-site-about">
                <h2>{{ $settings['about_title'] ?: __('About :name', ['name' => config('app.name')]) }}</h2>
                <div class="ld-site-about__text">
                    @foreach ($ldParagraphs as $paragraph)
                        <p>{!! nl2br(e($paragraph)) !!}</p>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Contact --}}
    @if ($settings['show_contact'] && ($settings['contact_email'] || $settings['contact_phone'] || $settings['contact_address'] || $settings['socials']))
        <section class="ld-site-section ld-site-section--tinted" id="contact">
            <div class="ld-site__container">
                <div class="ld-site-contact">
                    <div>
                        <h2>{{ __('Get in touch') }}</h2>
                        <p class="ld-site__muted">{{ __('Bookings, licensing, press or demos — we would like to hear from you.') }}</p>
                    </div>
                    <ul class="ld-site-contact__list">
                        @if ($settings['contact_email'])
                            <li><x-icon name="mail" size="18" /> <a href="mailto:{{ $settings['contact_email'] }}">{{ $settings['contact_email'] }}</a></li>
                        @endif
                        @if ($settings['contact_phone'])
                            <li><x-icon name="phone" size="18" /> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['contact_phone']) }}">{{ $settings['contact_phone'] }}</a></li>
                        @endif
                        @if ($settings['contact_address'])
                            <li><x-icon name="map-pin" size="18" /> <span>{{ $settings['contact_address'] }}</span></li>
                        @endif
                    </ul>
                </div>
            </div>
        </section>
    @endif

    @if (! $featured && $latest->isEmpty() && $artists->isEmpty() && $ldParagraphs->isEmpty())
        <section class="ld-site-section">
            <div class="ld-site__container text-center ld-site__muted">{{ __('New music is on its way. Check back soon.') }}</div>
        </section>
    @endif
</x-layouts.site>

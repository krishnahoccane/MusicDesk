@php
    $ldHeroImage = ($settings['hero_use_login_image'] ?? false) ? App\Support\Branding::url('login_image') : null;
    $ldParagraphs = collect(preg_split("/\R\s*\R/", trim((string) ($settings['about_text'] ?? ''))))->filter()->values();
    $ldStores = App\Services\Website\WebsiteContent::storeNames((string) $home['stores']);
    $ldServices = ($home['show_services'] && $servicesPage) ? $servicesPage->items('items') : [];
    $ldMd = fn (string $text) => \Illuminate\Support\Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    $ldLink = fn (string $url) => str_starts_with($url, 'https://') ? ['target' => '_blank', 'rel' => 'noopener'] : [];
@endphp
<x-layouts.site :settings="$settings" :image="$featured?->artwork_path ? route('site.artwork', [$featured->id, 'full']) : null">
    {{-- Hero --}}
    <section @class(['ld-site-hero', 'ld-site-hero--image' => $ldHeroImage])
             @if ($ldHeroImage) style="--ld-site-hero-image: url('{{ $ldHeroImage }}')" @endif>
        <div class="ld-site-hero__glow ld-site-hero__glow--a" aria-hidden="true"></div>
        <div class="ld-site-hero__glow ld-site-hero__glow--b" aria-hidden="true"></div>
        <div class="ld-site__container ld-site-hero__grid">
            <div class="ld-site-hero__copy">
                <div class="ld-site__eyebrow">{{ $home['eyebrow'] ?: __('Record label') }}</div>
                <h1 class="ld-site-hero__title">{{ $settings['hero_title'] ?: config('app.name') }}</h1>
                @if ($settings['hero_text'])
                    <p class="ld-site-hero__text">{{ $settings['hero_text'] }}</p>
                @endif
                <div class="ld-site-hero__actions">
                    @if ($home['cta1_label'] || $home['cta2_label'])
                        @if ($home['cta1_label'])
                            <a href="{{ $home['cta1_url'] }}" class="btn btn-primary btn-lg" @foreach ($ldLink($home['cta1_url']) as $k => $v) {{ $k }}="{{ $v }}" @endforeach>{{ $home['cta1_label'] }} <x-icon name="arrow-right" size="18" /></a>
                        @endif
                        @if ($home['cta2_label'])
                            <a href="{{ $home['cta2_url'] }}" class="btn btn-light btn-lg" @foreach ($ldLink($home['cta2_url']) as $k => $v) {{ $k }}="{{ $v }}" @endforeach>{{ $home['cta2_label'] }}</a>
                        @endif
                    @else
                        @if ($settings['show_releases'] && $releaseCount > 0)
                            <a href="{{ route('site.music') }}" class="btn btn-primary btn-lg">{{ __('Explore the music') }} <x-icon name="arrow-right" size="18" /></a>
                        @endif
                        @if ($settings['show_artists'] && $artistCount > 0)
                            <a href="{{ route('site.roster') }}" class="btn btn-light btn-lg">{{ __('Meet the artists') }}</a>
                        @endif
                    @endif
                </div>
                @if (! $home['stats'] && ($releaseCount > 0 || $artistCount > 0))
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

    {{-- Stores strip --}}
    @if ($ldStores)
        <section class="ld-site-stores" aria-label="{{ $home['stores_title'] ?: __('Stores') }}">
            <div class="ld-site__container">
                <div class="ld-site-stores__title">{{ $home['stores_title'] ?: __('Your music, everywhere') }}</div>
            </div>
            <div class="ld-site-stores__track">
                @foreach ([1, 2] as $pass)
                    <ul @if ($pass === 2) aria-hidden="true" @endif>
                        @foreach ($ldStores as $store) <li>{{ $store }}</li> @endforeach
                    </ul>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Numbers --}}
    @if ($home['stats'])
        <section class="ld-site-section ld-site-section--tight">
            <div class="ld-site__container">
                <dl class="ld-site-stats">
                    @foreach ($home['stats'] as $stat)
                        <div><dt>{{ $stat['value'] }}</dt><dd>{{ $stat['label'] }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </section>
    @endif

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
                            @include('site.partials.release-card', ['release' => $release, 'isUpcoming' => true])
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
                            @include('site.partials.release-card', ['release' => $release, 'isUpcoming' => false])
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Services (from the Services page) --}}
    @if ($ldServices)
        <section class="ld-site-section ld-site-section--tinted" id="services">
            <div class="ld-site__container">
                <div class="ld-site-section__head">
                    <h2>{{ $home['services_title'] ?: $servicesPage->title }}</h2>
                    <a href="{{ route('site.services') }}" class="ld-site__more">{{ __('All services') }} <x-icon name="arrow-right" size="16" /></a>
                </div>
                <div class="ld-site-services">
                    @foreach (array_slice($ldServices, 0, 6) as $item)
                        <article class="ld-site-service">
                            <span class="ld-site-service__no">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <h3>{{ $item['title'] ?? '' }}</h3>
                            @if (! empty($item['text'])) <div class="ld-site-prose">{!! $ldMd(\Illuminate\Support\Str::limit((string) $item['text'], 220)) !!}</div> @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- How it works --}}
    @if ($home['steps'])
        <section class="ld-site-section" id="how-it-works">
            <div class="ld-site__container">
                <div class="ld-site-section__head"><h2>{{ $home['steps_title'] ?: __('How it works') }}</h2></div>
                <ol class="ld-site-steps">
                    @foreach ($home['steps'] as $step)
                        <li>
                            <span class="ld-site-steps__no">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <h3>{{ $step['title'] }}</h3>
                            @if ($step['text']) <p class="ld-site__muted mb-0">{{ $step['text'] }}</p> @endif
                        </li>
                    @endforeach
                </ol>
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

    {{-- Testimonials --}}
    @if ($home['testimonials'])
        <section class="ld-site-section" id="testimonials">
            <div class="ld-site__container">
                <div class="ld-site-section__head"><h2>{{ $home['testimonials_title'] ?: __('What artists say') }}</h2></div>
                <div class="ld-site-quotes">
                    @foreach ($home['testimonials'] as $quote)
                        <figure class="ld-site-quote">
                            <blockquote>“{{ $quote['quote'] }}”</blockquote>
                            <figcaption>
                                <span class="ld-site-quote__avatar" aria-hidden="true">{{ \Illuminate\Support\Str::of($quote['name'])->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
                                <span><strong>{{ $quote['name'] }}</strong>@if ($quote['role'])<span class="d-block ld-site__muted small">{{ $quote['role'] }}</span>@endif</span>
                            </figcaption>
                        </figure>
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

    {{-- FAQ --}}
    @if ($home['faq'])
        <section class="ld-site-section ld-site-section--tinted" id="faq">
            <div class="ld-site__container ld-site-faq">
                <h2>{{ $home['faq_title'] ?: __('Frequently asked questions') }}</h2>
                <div>
                    @foreach ($home['faq'] as $faq)
                        <details class="ld-site-faq__item" @if ($loop->first) open @endif>
                            <summary>{{ $faq['q'] }} <x-icon name="chevron-down" size="18" /></summary>
                            <div class="ld-site-prose">{!! $ldMd($faq['a']) !!}</div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Closing call to action --}}
    @if ($home['cta_title'])
        <section class="ld-site-section ld-site-section--tight">
            <div class="ld-site__container">
                <div class="ld-site-cta">
                    <div>
                        <h2>{{ $home['cta_title'] }}</h2>
                        @if ($home['cta_text']) <p class="mb-0">{{ $home['cta_text'] }}</p> @endif
                    </div>
                    @if ($home['cta_label'])
                        <a href="{{ $home['cta_url'] }}" class="btn btn-light btn-lg" @foreach ($ldLink($home['cta_url']) as $k => $v) {{ $k }}="{{ $v }}" @endforeach>{{ $home['cta_label'] }} <x-icon name="arrow-right" size="18" /></a>
                    @endif
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

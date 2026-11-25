@php($ldArt = $release->artwork_path ? route('site.artwork', [$release->id, 'full']) : null)
<x-layouts.site :title="$release->displayTitle()" :settings="$settings" :image="$ldArt"
                :description="__(':title by :artists', ['title' => $release->displayTitle(), 'artists' => $release->artistLine()])">
    <section class="ld-site-release">
        @if ($ldArt)
            <div class="ld-site-release__backdrop" style="background-image: url('{{ $ldArt }}')" aria-hidden="true"></div>
        @endif
        <div class="ld-site__container ld-site-release__grid">
            <div class="ld-site-release__art">
                @if ($ldArt)
                    <img src="{{ $ldArt }}" alt="{{ __('Cover of :title', ['title' => $release->displayTitle()]) }}" width="900" height="900">
                @else
                    <span class="ld-site-card__placeholder" aria-hidden="true"><x-icon name="disc-3" size="72" /></span>
                @endif
            </div>
            <div>
                <a href="{{ route('site.music') }}" class="ld-site__back"><x-icon name="arrow-left" size="16" /> {{ __('All music') }}</a>
                <div class="ld-site__eyebrow">{{ $release->type->label() }}@if ($upcoming) · {{ __('Out :date', ['date' => $release->release_date->format('j M Y')]) }}@endif</div>
                <h1 class="ld-site-release__title">{{ $release->displayTitle() }}</h1>
                <div class="ld-site-release__artists">
                    @foreach ($release->artists as $artist)
                        @if ($artist->status->value === 'active' && $settings['show_artists'])
                            <a href="{{ route('site.artist', App\Services\Website\WebsiteContent::artistSlug($artist)) }}">{{ $artist->name }}</a>@if (! $loop->last), @endif
                        @else
                            {{ $artist->name }}@if (! $loop->last), @endif
                        @endif
                    @endforeach
                </div>
                <dl class="ld-site-release__facts">
                    @if ($release->release_date) <div><dt>{{ __('Released') }}</dt><dd>{{ $release->release_date->format('j M Y') }}</dd></div> @endif
                    @if ($release->genre) <div><dt>{{ __('Genre') }}</dt><dd>{{ $release->genre->name }}</dd></div> @endif
                    @if ($release->tracks->isNotEmpty()) <div><dt>{{ __('Tracks') }}</dt><dd>{{ $release->tracks->count() }}</dd></div> @endif
                    @if ($release->label_name) <div><dt>{{ __('Label') }}</dt><dd>{{ $release->label_name }}</dd></div> @endif
                </dl>

                @if ($release->tracks->isNotEmpty())
                    <ol class="ld-site-tracklist">
                        @foreach ($release->tracks as $track)
                            <li>
                                <span class="ld-site-tracklist__no">{{ $loop->iteration }}</span>
                                <span class="ld-site-tracklist__title">
                                    {{ $track->displayTitle() }}
                                    @if ($track->explicit) <span class="ld-site-tracklist__explicit" title="{{ __('Explicit') }}">E</span> @endif
                                    @if ($track->artistLine() !== $release->artistLine())
                                        <span class="ld-site__muted d-block small">{{ $track->artistLine() }}</span>
                                    @endif
                                </span>
                                <span class="ld-site-tracklist__time">{{ $track->duration_seconds ? $track->formattedDuration() : '' }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($release->c_line || $release->p_line)
                    <p class="ld-site__muted small mt-4 mb-0">{{ $release->c_line ? '© '.$release->c_line : '' }} {{ $release->p_line ? '℗ '.$release->p_line : '' }}</p>
                @endif
            </div>
        </div>
    </section>

    @if ($more->isNotEmpty())
        <section class="ld-site-section ld-site-section--tight">
            <div class="ld-site__container">
                <div class="ld-site-section__head"><h2>{{ __('More from :artists', ['artists' => $release->artistLine()]) }}</h2></div>
                <div class="ld-site-grid ld-site-grid--releases">
                    @foreach ($more as $other)
                        @include('site.partials.release-card', ['release' => $other])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.site>

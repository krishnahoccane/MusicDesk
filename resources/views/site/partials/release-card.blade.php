{{-- Needs: $release; optional $upcoming --}}
@php($ldSlug = App\Services\Website\WebsiteContent::releaseSlug($release))
<a href="{{ route('site.release', $ldSlug) }}" class="ld-site-card">
    <span class="ld-site-card__art">
        @if ($release->artwork_path)
            <img src="{{ route('site.artwork', $release->id) }}" alt="{{ __('Cover of :title', ['title' => $release->displayTitle()]) }}" loading="lazy" width="600" height="600">
        @else
            <span class="ld-site-card__placeholder" aria-hidden="true"><x-icon name="disc-3" size="40" /></span>
        @endif
        @if ($upcoming ?? false)
            <span class="ld-site-card__flag">{{ __('Out :date', ['date' => $release->release_date->format('j M')]) }}</span>
        @endif
    </span>
    <span class="ld-site-card__title">{{ $release->displayTitle() }}</span>
    <span class="ld-site-card__meta">{{ $release->artistLine() }}</span>
    <span class="ld-site-card__meta ld-site-card__meta--small">{{ $release->type->label() }} · {{ $release->release_date?->format('Y') }}</span>
</a>

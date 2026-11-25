{{-- Sub-navigation of Settings → Website --}}
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="{{ route('settings.website') }}" @class(['btn btn-sm', 'btn-primary' => request()->routeIs('settings.website'), 'btn-light' => ! request()->routeIs('settings.website')])>
        <x-icon name="sliders-horizontal" size="14" /> {{ __('Website and theme') }}
    </a>
    <a href="{{ route('settings.website.pages') }}" @class(['btn btn-sm', 'btn-primary' => request()->routeIs('settings.website.pages'), 'btn-light' => ! request()->routeIs('settings.website.pages')])>
        <x-icon name="files" size="14" /> {{ __('Pages and messages') }}
        @php($ldUnread = rescue(fn () => App\Models\SiteMessage::query()->whereNull('read_at')->count(), 0, false))
        @if ($ldUnread > 0) <span class="badge rounded-pill text-bg-danger ms-1">{{ $ldUnread }}</span> @endif
    </a>
    @if (app(App\Services\Website\WebsiteContent::class)->enabled())
        <a href="{{ url('/') }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost ms-auto"><x-icon name="external-link" size="14" /> {{ __('Open website') }}</a>
    @endif
</div>

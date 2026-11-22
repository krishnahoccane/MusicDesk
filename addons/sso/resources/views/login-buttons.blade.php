@php($ssoProviders = \LabelDesk\Addons\Sso\Models\SsoProvider::query()->enabled()->orderBy('name')->get())
@if ($ssoProviders->isNotEmpty())
    <div class="ld-sso mt-4">
        <div class="d-flex align-items-center gap-3 mb-3 text-muted small" role="separator">
            <hr class="flex-grow-1 my-0">
            <span>{{ __('or') }}</span>
            <hr class="flex-grow-1 my-0">
        </div>
        <div class="d-grid gap-2">
            @foreach ($ssoProviders as $ssoProvider)
                @if ($preview ?? false)
                    <span class="btn btn-light w-100 py-2 d-flex align-items-center justify-content-center gap-2 disabled" aria-disabled="true">
                        <x-icon :name="$ssoProvider->icon()" size="16" /> {{ $ssoProvider->buttonLabel() }}
                    </span>
                @else
                    <a href="{{ route('sso.redirect', $ssoProvider) }}" class="btn btn-light w-100 py-2 d-flex align-items-center justify-content-center gap-2">
                        <x-icon :name="$ssoProvider->icon()" size="16" /> {{ $ssoProvider->buttonLabel() }}
                    </a>
                @endif
            @endforeach
        </div>
        @if (app(\LabelDesk\Addons\Sso\SsoPolicy::class)->required())
            <p class="small text-muted mt-3 mb-0">{{ __('Staff accounts sign in with single sign-on.') }}</p>
        @endif
    </div>
@endif

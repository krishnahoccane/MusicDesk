{{-- Site-wide ribbon while an expired license is in its read-only window ($ribbonSnapshot). --}}
@if (isset($ribbonSnapshot) && $ribbonSnapshot->state === \App\Enums\LicenseState::Expired && $ribbonSnapshot->lockAt)
    @php($tz = config('labeldesk.display_timezone'))
    <div class="ld-ribbon ld-ribbon--danger" role="status">
        <x-icon name="alert-triangle" size="16" />
        <span>
            <strong>{{ __('License expired') }}</strong> &middot;
            {{ __('Read-only mode. This site will be locked on :date unless the license is renewed.', ['date' => $ribbonSnapshot->lockAt->setTimezone($tz)->format('M d, Y')]) }}
        </span>
        @auth
            @can('license.view')
                <a href="{{ route('license.show') }}" class="ld-ribbon__link">{{ __('License') }} &rarr;</a>
            @endcan
        @endauth
    </div>
@endif

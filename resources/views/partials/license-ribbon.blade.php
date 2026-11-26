{{-- Site-wide ribbon: an expired license in its read-only window, or a free trial ($ribbonSnapshot). --}}
@php
    $ldTrial = isset($ribbonSnapshot) && ($ribbonSnapshot->result?->trial ?? false);
    $ldTrialInfo = $ldTrial ? app(\App\Services\License\TrialLimits::class) : null;
    $tz = config('labeldesk.display_timezone');
@endphp
@if (isset($ribbonSnapshot) && $ribbonSnapshot->state === \App\Enums\LicenseState::Expired && $ribbonSnapshot->lockAt)
    <div class="ld-ribbon ld-ribbon--danger" role="status">
        <x-icon name="alert-triangle" size="16" />
        <span>
            @if ($ldTrial)
                <strong>{{ __('Your free trial has ended') }}</strong> &middot;
                {{ __(':product is read-only until :date, then it locks. Buy a license to continue — your data and settings are kept.', ['product' => config('labeldesk.product.name'), 'date' => $ribbonSnapshot->lockAt->setTimezone($tz)->format('M d, Y')]) }}
            @else
                <strong>{{ __('License expired') }}</strong> &middot;
                {{ __('Read-only mode. This site will be locked on :date unless the license is renewed.', ['date' => $ribbonSnapshot->lockAt->setTimezone($tz)->format('M d, Y')]) }}
            @endif
        </span>
        @if ($ldTrial)
            <a href="{{ $ldTrialInfo->buyUrl() }}" target="_blank" rel="noopener" class="ld-ribbon__link">{{ __('Buy :plan', ['plan' => $ldTrialInfo->planName()]) }} &rarr;</a>
        @else
            @auth
                @can('license.view')
                    <a href="{{ route('license.show') }}" class="ld-ribbon__link">{{ __('License') }} &rarr;</a>
                @endcan
            @endauth
        @endif
    </div>
@elseif ($ldTrial && auth()->check() && $ldTrialInfo->daysLeft() !== null)
    @php($ldDays = $ldTrialInfo->daysLeft())
    <div @class(['ld-ribbon', 'ld-ribbon--warning' => $ldTrialInfo->ending(), 'ld-ribbon--trial' => ! $ldTrialInfo->ending()]) role="status">
        <x-icon name="sparkles" size="16" />
        <span>
            <strong>{{ trans_choice('Free trial: :count day left|Free trial: :count days left', $ldDays) }}</strong>
            &middot; {{ __('ends :date', ['date' => $ldTrialInfo->endsAt()->setTimezone($tz)->format('M d, Y')]) }}
        </span>
        @can('license.view')
            <a href="{{ route('license.show') }}" class="ld-ribbon__link">{{ __('Trial limits') }}</a>
        @endcan
        <a href="{{ $ldTrialInfo->buyUrl() }}" target="_blank" rel="noopener" class="ld-ribbon__link">{{ __('Buy :plan', ['plan' => $ldTrialInfo->planName()]) }} &rarr;</a>
    </div>
@endif

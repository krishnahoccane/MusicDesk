@php($tz = config('labeldesk.display_timezone'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark" data-ld-theme="{{ $appearance['theme'] ?? 'musicdesk' }}" data-ld-font="{{ $appearance['font'] ?? 'inter-tight' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Suspended') }} · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss'])
</head>
<body class="ld-login">
    <div class="ld-login__spotlight">
        <div class="grid-bg ld-login__grid" aria-hidden="true"></div>
        <div class="ld-login__glow ld-login__glow--a" aria-hidden="true" style="background: rgba(255, 87, 87, .45)"></div>
        <div class="ld-login__spotlight-inner" style="max-width: 520px">
            <div class="ld-login__brand justify-content-center mb-4"><x-logo size="36" /> <span>{{ config('app.name') }}</span></div>
            <main class="card ld-login__card">
                <div class="card-body p-4 p-sm-5 text-center">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 56px; height: 56px; background: rgba(255,87,87,.12); color: #ff7a7a">
                        <x-icon name="lock" size="26" />
                    </span>
                    <div class="eyebrow mb-2" style="color: #ff7a7a">{{ __('Account suspended') }}</div>
                    <h1 class="ld-login__title mb-2">{{ __('This application is suspended') }}</h1>
                    <p class="text-muted mb-4">{{ __('The LabelDesk license for this site has expired and the 10-day read-only period has ended. Nobody can sign in until the license is renewed. No data has been deleted.') }}</p>

                    <dl class="row small text-start mb-4 mx-0 p-3 rounded-3" style="background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06)">
                        @if ($snapshot->expiredAt)
                            <dt class="col-6 fw-normal text-muted">{{ __('License expired') }}</dt>
                            <dd class="col-6 mb-1 text-end font-mono">{{ $snapshot->expiredAt->setTimezone($tz)->format('M d, Y') }}</dd>
                        @endif
                        @if ($snapshot->lockAt)
                            <dt class="col-6 fw-normal text-muted">{{ __('Suspended since') }}</dt>
                            <dd class="col-6 mb-1 text-end font-mono">{{ $snapshot->lockAt->setTimezone($tz)->format('M d, Y') }}</dd>
                        @endif
                        <dt class="col-6 fw-normal text-muted">{{ __('Site') }}</dt>
                        <dd class="col-6 mb-0 text-end font-mono text-break">{{ request()->getHost() }}</dd>
                    </dl>

                    @if (session('error'))
                        <div class="ld-notice mb-3 text-start" style="border-color: rgba(255,87,87,.3); background: rgba(255,87,87,.06)">{{ session('error') }}</div>
                    @endif

                    <p class="small text-muted mb-3">{{ __('Site owner: renew the license in your MusicDesk.tech account. Access returns automatically within an hour, or straight away with "Check again".') }}</p>

                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <a href="{{ config('licensing.customer_portal_url') }}" target="_blank" rel="noopener" class="btn btn-primary"><x-icon name="external-link" size="16" /> {{ __('Renew at MusicDesk.tech') }}</a>
                        <form method="POST" action="{{ route('license.locked.check') }}">
                            @csrf
                            <button type="submit" class="btn btn-light"><x-icon name="refresh-cw" size="16" /> {{ __('I have renewed — check again') }}</button>
                        </form>
                    </div>
                </div>
            </main>
            <div class="ld-login__note text-center">{{ config('app.name') }} &middot; {{ __('Powered by :product', ['product' => config('labeldesk.product.name')]) }}</div>
        </div>
    </div>
</body>
</html>

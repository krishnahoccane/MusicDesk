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
                    <p class="text-muted mb-4">
                        @if ($snapshot->expiredAt)
                            {{ __('The LabelDesk license for this site has expired and the 10-day read-only period has ended. Nobody can sign in until the license is renewed. No data has been deleted.') }}
                        @else
                            {{ $snapshot->reason }} {{ __('No data has been deleted.') }}
                        @endif
                    </p>

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

                    @php($changeErrors = $errors->getBag('changeKey'))
                    <details class="mt-4 text-start" @if ($changeErrors->any()) open @endif>
                        <summary class="small fw-semibold">{{ __('Administrator: use a different license key') }}</summary>
                        <form method="POST" action="{{ route('license.locked.change-key') }}" class="mt-3" novalidate>
                            @csrf
                            <p class="small text-muted">{{ __('Enter a new key from your MusicDesk.tech account and the sign-in details of an administrator who may manage the license. The key is checked with MusicDesk.tech before anything changes.') }}</p>
                            <div class="mb-2">
                                <label class="form-label small" for="ck-key">{{ __('New license key') }}</label>
                                <input id="ck-key" name="license_key" class="form-control font-monospace text-uppercase @if ($changeErrors->has('license_key')) is-invalid @endif" placeholder="LDSK-XXXX-XXXX-XXXX-XXXX" maxlength="64" autocomplete="off">
                                @if ($changeErrors->has('license_key')) <div class="invalid-feedback">{{ $changeErrors->first('license_key') }}</div> @endif
                            </div>
                            <div class="mb-2">
                                <label class="form-label small" for="ck-email">{{ __('Administrator email') }}</label>
                                <input id="ck-email" type="email" name="email" value="{{ old('email') }}" class="form-control @if ($changeErrors->has('email')) is-invalid @endif" autocomplete="username">
                                @if ($changeErrors->has('email')) <div class="invalid-feedback">{{ $changeErrors->first('email') }}</div> @endif
                            </div>
                            <div class="mb-3">
                                <label class="form-label small" for="ck-password">{{ __('Password') }}</label>
                                <input id="ck-password" type="password" name="password" class="form-control @if ($changeErrors->has('password')) is-invalid @endif" autocomplete="current-password">
                                @if ($changeErrors->has('password')) <div class="invalid-feedback">{{ $changeErrors->first('password') }}</div> @endif
                            </div>
                            <button type="submit" class="btn btn-primary w-100"><x-icon name="key-round" size="16" /> {{ __('Change key') }}</button>
                        </form>
                    </details>
                </div>
            </main>
            <div class="ld-login__note text-center">&copy; {{ now()->year }} {{ config('app.name') }}</div>
        </div>
    </div>
</body>
</html>

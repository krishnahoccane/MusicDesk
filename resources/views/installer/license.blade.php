<x-layouts.installer :title="__('License')" :step="$step">
    <div class="eyebrow mb-2">{{ __('Step :n', ['n' => 0]) }}</div>
    <h1>{{ __('Validate your license') }}</h1>
    <p class="ld-installer__lead">{{ __('Enter the license key from your MusicDesk.tech account. LabelDesk checks it over HTTPS and activates it for this site\'s domain.') }}</p>

    @if ($driver === 'mock')
        <div class="ld-notice mb-4" style="border-color: rgba(var(--ld-warning-rgb), .3); background: rgba(var(--ld-warning-rgb), .06)">
            <strong style="color: var(--ld-warning)">{{ __('Development:') }}</strong>
            {{ __('this installation uses the mock license provider. It cannot activate real licenses and is refused in production.') }}
        </div>
    @endif

    @if ($activated)
        <div class="ld-notice mb-4" style="border-color: rgba(var(--ld-success-rgb), .3); background: rgba(var(--ld-success-rgb), .06)">
            <div class="d-flex gap-3 align-items-start">
                <span class="ld-stat__icon ld-stat__icon--success"><x-icon name="badge-check" size="20" /></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold" style="color: var(--ld-heading)">{{ $offline ? __('Offline activation accepted') : __('License valid') }}</div>
                    <div class="small font-mono text-muted mb-2">{{ $maskedKey }} &middot; {{ $domain }}</div>
                    <div class="d-flex flex-wrap gap-2 small">
                        <x-badge variant="accent" class="text-capitalize">{{ $activated['plan'] }}</x-badge>
                        <x-badge variant="success" class="text-capitalize">{{ $activated['status'] }}</x-badge>
                        @if (! empty($activated['support_until']))
                            <span class="text-muted">{{ __('Updates & support until :date', ['date' => \Illuminate\Support\Carbon::parse($activated['support_until'])->format('M d, Y')]) }}</span>
                        @endif
                    </div>
                    @foreach ($activated['messages'] ?? [] as $notice)
                        <div class="small mt-2"><x-icon name="info" size="14" /> {{ $notice }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('installer.license.store') }}" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="url">{{ __('Site address') }} <span class="text-subtle">({{ __('the license is activated for this domain') }})</span></label>
                <input id="url" name="url" class="form-control @error('url') is-invalid @enderror" value="{{ old('url', $url) }}" required maxlength="255" autocomplete="off">
                @error('url') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="license_key">{{ $activated ? __('Use a different license key') : __('License key') }}</label>
                <div class="input-icon">
                    <x-icon name="key-round" size="16" />
                    <input id="license_key" name="license_key" class="form-control font-mono text-uppercase @error('license_key') is-invalid @enderror"
                           value="{{ old('license_key') }}" placeholder="LDSK-XXXX-XXXX-XXXX-XXXX" maxlength="64" autocomplete="off" spellcheck="false" @if (! $activated) required autofocus @endif>
                </div>
                @error('license_key') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-check mt-3">
            <input class="form-check-input @error('accept') is-invalid @enderror" type="checkbox" name="accept" value="1" id="accept" checked>
            <label class="form-check-label small" for="accept">{{ __('Allow LabelDesk to contact MusicDesk.tech over HTTPS to activate this license and check in once a day.') }}</label>
            @error('accept') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn {{ $activated ? 'btn-light' : 'btn-primary' }} mt-3"><x-icon name="shield-check" size="16" /> {{ __('Validate license') }}</button>
    </form>

    @if (session('diagnosis'))
        @php($diagnosis = session('diagnosis'))
        <div class="ld-notice mt-4" @style(['border-color: rgba(var(--ld-danger-rgb), .3); background: rgba(var(--ld-danger-rgb), .05)' => ! $diagnosis['ok']])>
            <div class="fw-semibold mb-2" style="color: var(--ld-heading)">{{ $diagnosis['ok'] ? __('This server can reach the license server.') : __('This server cannot use the license server yet.') }}</div>
            <ul class="list-unstyled small mb-2">
                @foreach ($diagnosis['checks'] as $check)
                    <li class="d-flex gap-2 mb-1">
                        <x-icon :name="$check['ok'] ? 'circle-check-big' : 'circle-x'" size="15" @style([$check['ok'] ? 'color: var(--ld-success)' : 'color: var(--ld-danger)', 'flex-shrink: 0; margin-top: 3px']) />
                        <span><strong>{{ $check['label'] }}</strong><br><span class="font-mono text-muted" style="font-size: 12px">{{ $check['detail'] }}</span></span>
                    </li>
                @endforeach
            </ul>
            <div class="small text-muted font-mono">
                {{ __('Server IP: :ip', ['ip' => $diagnosis['ip'] ?? __('unknown')]) }}@if ($diagnosis['ray']) &middot; {{ __('Cloudflare Ray ID: :ray', ['ray' => $diagnosis['ray']]) }}@endif
            </div>
            @if ($diagnosis['advice'])
                <p class="small mt-2 mb-0">{{ $diagnosis['advice'] }}</p>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('installer.license.diagnose') }}" class="mt-3">
        @csrf
        <button type="submit" class="btn btn-sm btn-ghost"><x-icon name="activity" size="15" /> {{ __('Test connection to MusicDesk.tech') }}</button>
    </form>

    <details class="mt-4" @if ($errors->has('license_file')) open @endif>
        <summary class="fw-semibold small" style="cursor: pointer">{{ __('This server has no internet access?') }}</summary>
        <div class="small text-muted mt-2">
            <ol class="ps-3 mb-3">
                <li><a href="{{ route('installer.license.request') }}">{{ __('Download the activation request file') }}</a> {{ __('for :domain.', ['domain' => $domain]) }}</li>
                <li>{{ __('On a computer with internet, upload it in your MusicDesk account under Licenses → Offline activation.') }}</li>
                <li>{{ __('Upload the .ldlic file you receive here. It is valid for one year.') }}</li>
            </ol>
            <form method="POST" action="{{ route('installer.license.offline') }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-start">
                @csrf
                <div>
                    <input type="file" name="license_file" accept=".ldlic,application/json" class="form-control @error('license_file') is-invalid @enderror" aria-label="{{ __('Offline activation file') }}">
                    @error('license_file') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="btn btn-light"><x-icon name="upload" size="16" /> {{ __('Use activation file') }}</button>
            </form>
        </div>
    </details>

    <form method="POST" action="{{ route('installer.license.continue') }}">
        @csrf
        <div class="ld-installer__actions">
            <a href="{{ route('installer.welcome') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary" @disabled(! $activated)>{{ __('Continue') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

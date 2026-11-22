<x-layouts.installer :title="__('License')" :step="$step">
    <h1>{{ __('License activation') }}</h1>
    <p class="ld-installer__lead">{{ __('Enter the license key from your MusicDesk.tech account. LabelDesk contacts MusicDesk.tech over HTTPS to activate it for this domain.') }}</p>

    @if ($driver === 'mock')
        <x-alert variant="warning" class="mb-4" :title="__('Development license provider')">
            {{ __('This installation uses the development mock provider. It cannot activate real licenses and is refused in production.') }}
        </x-alert>
    @endif

    @if ($activated)
        <div class="card mb-4" style="background: var(--ld-success-soft); border-color: transparent">
            <div class="card-body d-flex gap-3 align-items-start">
                <span class="ld-stat__icon ld-stat__icon--success"><x-icon name="badge-check" size="20" /></span>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="color: var(--ld-heading)">{{ $offline ? __('Offline activation accepted') : __('License activated') }}</div>
                    <div class="small text-muted mb-3">{{ $maskedKey }} &middot; {{ $domain }}</div>
                    <dl class="ld-dl mb-0">
                        <dt>{{ __('Plan') }}</dt><dd class="text-capitalize">{{ $activated['plan'] }}</dd>
                        <dt>{{ __('Status') }}</dt><dd class="text-capitalize">{{ $activated['status'] }}</dd>
                        <dt>{{ __('Updates & support until') }}</dt><dd>{{ ! empty($activated['support_until']) ? \Illuminate\Support\Carbon::parse($activated['support_until'])->format(config('labeldesk.date_format')) : '—' }}</dd>
                        <dt>{{ __('Features') }}</dt>
                        <dd>
                            @foreach ($activated['features'] as $feature)
                                <x-badge variant="accent" class="me-1 mb-1">{{ __(config('licensing.features.'.$feature, $feature)) }}</x-badge>
                            @endforeach
                        </dd>
                    </dl>
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
            <div class="col-md-8">
                <label class="form-label" for="license_key">{{ $activated ? __('Use a different license key') : __('License key') }}</label>
                <div class="input-icon">
                    <x-icon name="key-round" size="16" />
                    <input id="license_key" name="license_key" class="form-control font-monospace text-uppercase @error('license_key') is-invalid @enderror"
                           value="{{ old('license_key') }}" placeholder="LDSK-XXXX-XXXX-XXXX-XXXX" maxlength="64" autocomplete="off" spellcheck="false" @if (! $activated) required @endif>
                </div>
                @error('license_key') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('Activation domain') }}</label>
                <input class="form-control" value="{{ $domain }}" readonly aria-readonly="true">
            </div>
        </div>
        <div class="form-check mt-3">
            <input class="form-check-input @error('accept') is-invalid @enderror" type="checkbox" name="accept" value="1" id="accept" checked>
            <label class="form-check-label small" for="accept">{{ __('Allow LabelDesk to contact MusicDesk.tech over HTTPS to activate this license and check in once a day.') }}</label>
            @error('accept') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn {{ $activated ? 'btn-light' : 'btn-primary' }} mt-3"><x-icon name="shield-check" size="16" /> {{ __('Validate & activate') }}</button>
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
            <a href="{{ route('installer.administrator') }}" class="btn btn-light"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary" @disabled(! $activated)>{{ __('Continue') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

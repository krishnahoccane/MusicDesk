<x-layouts.installer :title="__('Redis')" :step="$step">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="eyebrow">{{ __('Step :n', ['n' => 3]) }}</span>
        <x-badge>{{ __('optional') }}</x-badge>
    </div>
    <h1>{{ __('Connect Redis') }}</h1>
    <p class="ld-installer__lead">{{ __('Redis makes LabelDesk faster on busy servers. Most shared hosting does not offer it. Skip this step and LabelDesk uses the database instead, which works everywhere.') }}</p>

    @error('redis')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    @if ($enabled)
        <div class="ld-notice mb-4" style="border-color: rgba(var(--ld-success-rgb), .3); background: rgba(var(--ld-success-rgb), .06)">
            <strong style="color: var(--ld-success)">{{ __('Connected:') }}</strong> {{ __('Redis is set up. Change the details below to test again, or continue.') }}
        </div>
    @endif

    <form method="POST" action="{{ route('installer.redis.store') }}" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label" for="host">{{ __('Host') }}</label>
                <input id="host" name="host" class="form-control font-mono @error('host') is-invalid @enderror" value="{{ old('host', $values['host']) }}" maxlength="255" autocomplete="off">
                @error('host') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="port">{{ __('Port') }}</label>
                <input id="port" name="port" type="number" min="1" max="65535" class="form-control font-mono @error('port') is-invalid @enderror" value="{{ old('port', $values['port']) }}">
                @error('port') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="username">{{ __('Username') }} <span class="text-subtle">({{ __('optional') }})</span></label>
                <input id="username" name="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username', $values['username']) }}" maxlength="128" autocomplete="off">
            </div>
            <div class="col-md-5">
                <label class="form-label" for="password">{{ __('Password') }} <span class="text-subtle">({{ __('optional') }})</span></label>
                <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" maxlength="255" autocomplete="new-password"
                       placeholder="{{ $hasPassword ? __('Leave blank to keep the saved password') : '' }}">
                @if ($hasPassword) <input type="hidden" name="keep_password" value="1"> @endif
            </div>
            <div class="col-md-3">
                <label class="form-label" for="database">{{ __('Database no.') }}</label>
                <input id="database" name="database" type="number" min="0" max="15" class="form-control font-mono @error('database') is-invalid @enderror" value="{{ old('database', $values['database']) }}">
                @error('database') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="form-check mt-3">
            <input type="hidden" name="tls" value="0">
            <input class="form-check-input" type="checkbox" name="tls" value="1" id="tls" @checked(old('tls', $values['tls']))>
            <label class="form-check-label" for="tls">{{ __('Use TLS (managed Redis services)') }}</label>
        </div>

        <h2 class="ld-installer__section mt-4">{{ __('Use Redis for') }}</h2>
        <div class="row g-2">
            @foreach (['use_cache' => [__('Cache'), __('Settings, permissions and license checks.')], 'use_sessions' => [__('Sessions'), __('Sign-ins.')], 'use_queue' => [__('Queue'), __('Statement imports and emails.')]] as $field => [$label, $hint])
                <div class="col-md-4">
                    <label class="ld-option-card">
                        <input type="hidden" name="{{ $field }}" value="0">
                        <span class="d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $values[$field]))>
                            <span class="fw-semibold small" style="color: var(--ld-heading)">{{ $label }}</span>
                        </span>
                        <span class="d-block ld-list__meta mt-1">{{ $hint }}</span>
                    </label>
                </div>
            @endforeach
        </div>
        <p class="small text-muted mt-3 mb-0">{{ __('Client: :client. The connection is tested before continuing.', ['client' => $client === 'phpredis' ? 'phpredis (PHP extension)' : 'Predis (built in)']) }}</p>

        <div class="ld-installer__actions">
            <a href="{{ route('installer.database') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <div class="d-flex gap-2">
                <button type="submit" form="skip-redis" class="btn btn-ghost">{{ __('Skip, use the database') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Test & continue') }} <x-icon name="arrow-right" size="16" /></button>
            </div>
        </div>
    </form>
    <form id="skip-redis" method="POST" action="{{ route('installer.redis.skip') }}">@csrf</form>
</x-layouts.installer>

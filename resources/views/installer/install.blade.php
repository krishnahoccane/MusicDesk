<x-layouts.installer :title="__('Install')" :step="$step">
    <h1>{{ __('Ready to install') }}</h1>
    <p class="ld-installer__lead">{{ __('Review the summary below. Installation creates the database tables, default roles and your administrator account.') }}</p>

    @error('install')
        <x-alert variant="danger" class="mb-4" :title="__('Installation failed')">{{ $message }}</x-alert>
    @enderror

    @if (! empty($database['resuming']))
        <x-alert variant="warning" class="mb-4">{{ __('A previous installation into this database did not finish. Installing again resumes it — no existing data is removed.') }}</x-alert>
    @endif

    <div class="row g-4">
        <div class="col-md-6">
            <h2 class="h6 fw-semibold">{{ __('Database') }}</h2>
            <dl class="ld-dl">
                <dt>{{ __('Server') }}</dt><dd>{{ $database['server'] ?? '' }} {{ $database['version'] ?? '' }}</dd>
                <dt>{{ __('Host') }}</dt><dd>{{ $database['host'] }}:{{ $database['port'] }}</dd>
                <dt>{{ __('Database') }}</dt><dd>{{ $database['database'] }}</dd>
                <dt>{{ __('Prefix') }}</dt><dd>{{ $database['prefix'] ?: '—' }}</dd>
            </dl>
        </div>
        <div class="col-md-6">
            <h2 class="h6 fw-semibold">{{ __('Application') }}</h2>
            <dl class="ld-dl">
                <dt>{{ __('Name') }}</dt><dd>{{ $application['name'] }}</dd>
                <dt>{{ __('URL') }}</dt><dd class="text-break">{{ $application['url'] }}</dd>
                <dt>{{ __('Timezone') }}</dt><dd>{{ $application['timezone'] }}</dd>
                <dt>{{ __('Currency') }}</dt><dd>{{ $application['currency'] }}</dd>
            </dl>
        </div>
        <div class="col-md-6">
            <h2 class="h6 fw-semibold">{{ __('Administrator') }}</h2>
            <dl class="ld-dl">
                <dt>{{ __('Name') }}</dt><dd>{{ $administrator['name'] }}</dd>
                <dt>{{ __('Email') }}</dt><dd>{{ $administrator['email'] }}</dd>
            </dl>
        </div>
        <div class="col-md-6">
            <h2 class="h6 fw-semibold">{{ __('License') }}</h2>
            <dl class="ld-dl">
                <dt>{{ __('Plan') }}</dt><dd class="text-capitalize">{{ $license['plan'] ?? '—' }}</dd>
                <dt>{{ __('Status') }}</dt><dd><x-badge variant="success">{{ __('Activated') }}</x-badge></dd>
            </dl>
        </div>
    </div>

    <form method="POST" action="{{ route('installer.install.store') }}" onsubmit="this.classList.add('is-submitting'); this.querySelector('button[type=submit]').disabled = true;">
        @csrf
        <div class="ld-installer__actions">
            <a href="{{ route('installer.license') }}" class="btn btn-light"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary">
                <span class="ld-install-label d-inline-flex align-items-center gap-2"><x-icon name="rocket" size="16" /> {{ __('Install LabelDesk') }}</span>
                <span class="ld-installing align-items-center gap-2"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ __('Installing — this can take a minute…') }}</span>
            </button>
        </div>
    </form>
</x-layouts.installer>

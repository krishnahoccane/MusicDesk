<x-layouts.installer :title="__('Install')" :step="$step">
    <div class="eyebrow mb-2">{{ __('Review') }}</div>
    <h1>{{ __('Ready to install') }}</h1>
    <p class="ld-installer__lead">{{ __('Check the summary. Installing creates the database tables, roles and your super admin account, then applies your settings.') }}</p>

    @error('install')
        <x-alert variant="danger" class="mb-4" :title="__('Installation failed')">{{ $message }}</x-alert>
    @enderror

    @if (! empty($database['resuming']))
        <x-alert variant="warning" class="mb-4">{{ __('A previous installation into this database did not finish. Installing again resumes it — no existing data is removed.') }}</x-alert>
    @endif

    @php
        $rows = [
            ['0', __('License'), 'key-round', ucfirst((string) ($license['plan'] ?? '—')).' · '.($license['domain'] ?? ''), 'installer.license'],
            ['1', __('Server check'), 'server', __('Passed'), 'installer.requirements'],
            ['2', __('Database'), 'database', trim(($database['server'] ?? '').' '.($database['version'] ?? '')).' · '.$database['database'].($database['prefix'] ? ' ('.$database['prefix'].')' : ''), 'installer.database'],
            ['3', __('Redis'), 'zap', $redis['enabled'] ?? false
                ? __('On for :list', ['list' => implode(', ', array_keys(array_filter(['cache' => $redis['use_cache'] ?? false, 'sessions' => $redis['use_sessions'] ?? false, 'queue' => $redis['use_queue'] ?? false])))])
                : __('Skipped — using the database'), 'installer.redis'],
            ['4', __('Email'), 'mail', ($mail['mailer'] ?? 'log') === 'smtp' ? $mail['host'].' · '.$mail['from_address'] : __('Skipped — set up later in Settings → Email'), 'installer.mail'],
            ['5', __('Super admin'), 'user-cog', $administrator['name'].' · '.$administrator['email'], 'installer.administrator'],
            ['6', __('Customise'), 'palette', $application['name'].' · '.$appearanceSummary, 'installer.customize'],
        ];
    @endphp

    <ul class="ld-list border rounded-4">
        @foreach ($rows as [$n, $label, $icon, $value, $route])
            <li class="ld-list__item">
                <span class="ld-list__icon"><x-icon :name="$icon" size="16" /></span>
                <span class="flex-grow-1 min-w-0">
                    <span class="d-block small fw-semibold" style="color: var(--ld-heading)"><span class="font-mono text-subtle me-1">{{ $n }}</span> {{ $label }}</span>
                    <span class="ld-list__meta text-break">{{ $value }}</span>
                </span>
                <a href="{{ route($route) }}" class="btn btn-sm btn-ghost">{{ __('Change') }}</a>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('installer.install.store') }}" onsubmit="this.classList.add('is-submitting'); this.querySelector('button[type=submit]').disabled = true;">
        @csrf
        <div class="ld-installer__actions">
            <a href="{{ route('installer.customize') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary">
                <span class="ld-install-label d-inline-flex align-items-center gap-2"><x-icon name="rocket" size="16" /> {{ __('Install LabelDesk') }}</span>
                <span class="ld-installing align-items-center gap-2"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ __('Installing — this can take a minute…') }}</span>
            </button>
        </div>
    </form>
</x-layouts.installer>

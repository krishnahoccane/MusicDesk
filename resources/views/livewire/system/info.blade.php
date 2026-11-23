<div>
    <x-page-header :title="__('System')" :subtitle="__('Health of this installation and database upgrades.')" />

    @if ($upgradeFailure)
        <x-alert variant="danger" class="mb-3">
            <strong>{{ __('The automatic database update to :version failed.', ['version' => $upgradeFailure['version']]) }}</strong>
            {{ $upgradeFailure['error'] }}
            {{ __('It is tried again every 15 minutes; you can also run it below.') }}
        </x-alert>
    @endif

    @if ($upgradeNeeded)
        <div class="card mb-3 border-warning">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <span class="ld-list__icon ld-list__icon--warning"><x-icon name="arrow-up-circle" size="18" /></span>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="color: var(--ld-heading)">{{ __('A database upgrade is ready') }}</div>
                    <div class="small text-muted">
                        {{ __('Files for :version were uploaded (database is at :installed, :count changes waiting). It runs on its own the next time a page is opened; you can also run it now.', [
                            'version' => config('labeldesk.product.version'), 'installed' => $installedVersion ?? '—', 'count' => count($pending),
                        ]) }}
                    </div>
                </div>
                <a href="{{ route('backups.index') }}" class="btn btn-light">{{ __('Back up first') }}</a>
                <button type="button" class="btn btn-primary" wire:click="upgrade" wire:loading.attr="disabled" wire:target="upgrade"
                        wire:confirm="{{ __('Run the database upgrade now? Make sure you have a recent backup.') }}">
                    <span wire:loading wire:target="upgrade" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    {{ __('Run upgrade') }}
                </button>
            </div>
        </div>
    @endif

    <div class="row g-3">
        @foreach ($sections as $section)
            <div class="col-lg-6 col-xxl-4">
                <div class="card h-100">
                    <div class="card-header">
                        <x-icon :name="$section['icon']" size="18" />
                        <h2 class="card-title">{{ $section['title'] }}</h2>
                    </div>
                    <dl class="ld-dl card-body py-2 mb-0">
                        @foreach ($section['items'] as $item)
                            <dt>{{ $item['label'] }}</dt>
                            <dd class="d-flex align-items-center gap-2">
                                @isset($item['state'])
                                    <span @class(['ld-health__dot', 'ld-health__dot--'.$item['state'] => $item['state'] !== 'ok'])></span>
                                @endisset
                                <span class="text-break">{{ $item['value'] }}</span>
                            </dd>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <x-icon name="timer" size="18" />
            <h2 class="card-title">{{ __('Automatic tasks') }}</h2>
            <span class="small text-muted ms-auto">{{ __('No cron job needed') }}</span>
        </div>
        <div class="card-body small">
            <p class="text-muted">{{ __('LabelDesk runs its background work by itself while people use it, after each page has been sent (nobody waits for it).') }}</p>
            @php($labels = [
                'queue' => __('Queued jobs (statement imports, emails)'),
                'media' => __('Cloud media uploads and clean-up'),
                'backup' => __('Nightly backup (when turned on)'),
                'cleanup' => __('Activity log clean-up'),
            ])
            <dl class="ld-dl mb-3">
                @foreach ($tasks as $task => $info)
                    <dt>{{ $labels[$task] ?? $task }}</dt>
                    <dd>
                        <span class="text-muted">{{ $task === 'backup' ? __('daily, from 02:00') : ($info['every'] >= 1440 ? __('daily') : trans_choice('every minute|every :count minutes', $info['every'])) }}</span>
                        &middot; {{ $info['last'] ? __('last :time', ['time' => \Carbon\CarbonImmutable::createFromTimestamp($info['last'])->diffForHumans()]) : __('not run yet') }}
                    </dd>
                @endforeach
            </dl>
            <p class="text-muted mb-0">{{ __('On a site nobody visits, tasks wait for the next visit; license expiry and lock dates are always enforced. With SSH you can also run') }} <code>php artisan labeldesk:tasks:run</code> {{ __('or the upgrade with') }} <code>php artisan labeldesk:upgrade</code>.</p>
        </div>
    </div>
</div>

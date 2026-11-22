<div>
    <x-page-header :title="__('System')" :subtitle="__('Health of this installation and database upgrades.')" />

    @if ($upgradeNeeded)
        <div class="card mb-3 border-warning">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <span class="ld-list__icon ld-list__icon--warning"><x-icon name="arrow-up-circle" size="18" /></span>
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="color: var(--ld-heading)">{{ __('A database upgrade is ready') }}</div>
                    <div class="small text-muted">
                        {{ __('Files for :version were uploaded (database is at :installed, :count changes waiting). Take a backup, then run the upgrade.', [
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
            <x-icon name="terminal" size="18" />
            <h2 class="card-title">{{ __('Cron job') }}</h2>
        </div>
        <div class="card-body small">
            <p class="text-muted">{{ __('Add this command in cPanel → Cron Jobs, set to run every minute. It runs the license check, queued emails, the nightly backup and clean-up.') }}</p>
            <code class="d-block p-2 rounded" style="background: var(--ld-surface-2)">* * * * * {{ $cron }}</code>
            <p class="text-muted mt-3 mb-0">{{ __('With SSH access you can also run the upgrade with:') }} <code>php artisan labeldesk:upgrade</code></p>
        </div>
    </div>
</div>

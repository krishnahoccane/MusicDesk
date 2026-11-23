<div x-data="{
        running: @js($progress !== null),
        message: @js($progress['message'] ?? ''),
        failed: '',
        timer: null,
        follow() {
            this.running = true;
            this.timer ??= setInterval(async () => {
                try {
                    const r = await fetch(@js(route('updates.progress')), { headers: { Accept: 'application/json' }, cache: 'no-store' });
                    if (! r.ok) return; // 503 while files are swapped: keep waiting
                    const p = await r.json();
                    if (p.message) this.message = p.message;
                    if (p.state === 'done') { clearInterval(this.timer); setTimeout(() => window.location.reload(), 1500); }
                    if (p.state === 'failed') { clearInterval(this.timer); this.running = false; this.failed = p.message; }
                } catch (e) { /* server busy or restarting */ }
            }, 2000);
        },
    }" x-init="if (running) follow()" @update-started.window="message = @js(__('Starting the update…')); follow()">
    <x-page-header :title="__('Updates')" :subtitle="__('New versions of :product and its add-ons from MusicDesk.tech.', ['product' => config('labeldesk.product.name')])">
        <x-slot:actions>
            @if ($availability === null)
                <button type="button" class="btn btn-light" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">
                    <span wire:loading wire:target="checkNow" class="spinner-border spinner-border-sm"></span>
                    <x-icon name="refresh-cw" size="16" /> {{ __('Check again') }}
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div x-show="running" x-cloak class="alert alert-info" role="status" aria-live="polite">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span x-text="message"></span>
            <span class="small text-muted ms-auto">{{ __('Keep this page open. The site shows a short maintenance page while files are replaced.') }}</span>
        </div>
    </div>
    <div x-show="failed" x-cloak class="alert alert-danger" role="alert">
        <strong>{{ __('The update was not installed.') }}</strong> <span x-text="failed"></span>
    </div>

    @if ($upgradeFailure)
        <x-alert variant="danger" class="mb-3">
            <strong>{{ __('The database update to :version failed.', ['version' => $upgradeFailure['version']]) }}</strong>
            {{ $upgradeFailure['error'] }}
            {{ __('It is tried again automatically every 15 minutes, or use “Upgrade database” under Administration → System.') }}
        </x-alert>
    @endif

    @if ($availability !== null)
        <x-alert variant="info" class="mb-3">{{ $availability }}</x-alert>
    @elseif ($status['error'])
        <x-alert variant="warning" class="mb-3">{{ __('Could not check for updates: :reason', ['reason' => $status['error']]) }}</x-alert>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- LabelDesk itself --}}
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="package" size="18" />
                    <h2 class="card-title">{{ config('labeldesk.product.name') }}</h2>
                    <span class="font-mono small text-muted ms-auto">v{{ $version }}</span>
                </div>
                <div class="card-body">
                    @if ($core)
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <x-badge variant="accent">{{ __('Version :version is available', ['version' => $core['version']]) }}</x-badge>
                            @if ($core['released_at'])
                                <span class="small text-muted">{{ __('released :date', ['date' => \Illuminate\Support\Carbon::parse($core['released_at'])->format(config('labeldesk.date_format', 'd M Y'))]) }}</span>
                            @endif
                        </div>
                        @if ($coreChangelog)
                            <details class="mb-3">
                                <summary class="small fw-semibold">{{ __('What’s new') }}</summary>
                                <div class="small mt-2">{!! $coreChangelog !!}</div>
                            </details>
                        @endif
                        @if ($coreBlocker)
                            <x-alert variant="warning" class="mb-0">{{ $coreBlocker }}</x-alert>
                        @elseif ($serverProblems !== [])
                            <x-alert variant="warning" class="mb-0">
                                {{ __('This server does not allow updates from here:') }} {{ implode(' ', $serverProblems) }}
                                {{ __('Download the new version from your MusicDesk.tech account and upload the files by FTP or the hosting file manager; the database update then runs on its own the next time a page is opened.') }}
                            </x-alert>
                        @else
                            <p class="small text-muted">{{ __('A database backup is made first (when backups are available). Your settings, uploads, .env and add-ons are not touched.') }}</p>
                            <button type="button" class="btn btn-primary" wire:click="installCore" x-bind:disabled="running"
                                    wire:confirm="{{ __('Update :product to :version now? The site is unavailable for about a minute.', ['product' => config('labeldesk.product.name'), 'version' => $core['version']]) }}">
                                <x-icon name="download" size="16" /> {{ __('Update now') }}
                            </button>
                        @endif
                    @elseif ($availability === null)
                        <p class="mb-0 d-flex align-items-center gap-2"><x-icon name="circle-check" size="18" class="text-success" /> {{ __('You have the latest version.') }}</p>
                    @else
                        <p class="mb-0 text-muted">{{ __('Installed version :version.', ['version' => $version]) }}</p>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="auto-core" @checked($autoCore) wire:click="toggleAutoCore" @disabled($availability !== null)>
                        <label class="form-check-label" for="auto-core">{{ __('Install :product updates automatically', ['product' => config('labeldesk.product.name')]) }}</label>
                    </div>
                </div>
            </div>

            {{-- Add-ons --}}
            <div class="card">
                <div class="card-header">
                    <x-icon name="puzzle" size="18" />
                    <h2 class="card-title">{{ __('Add-ons') }}</h2>
                </div>
                @if ($addons->isEmpty())
                    <x-empty-state icon="puzzle" :title="__('No add-ons installed')" :description="__('Add-ons you install appear here with their updates.')" />
                @else
                    <ul class="ld-list">
                        @foreach ($addons as $a)
                            @php($m = $a['model'])
                            <li class="ld-list__item flex-wrap" wire:key="addon-update-{{ $m->id }}">
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="fw-semibold" style="color: var(--ld-heading)">{{ $m->name }}</span>
                                        <span class="font-mono small text-muted">v{{ $m->version }}</span>
                                        @if ($a['release'])
                                            <x-badge variant="accent">{{ __(':version available', ['version' => $a['release']['version']]) }}</x-badge>
                                        @elseif ($availability === null)
                                            <x-badge variant="success">{{ __('up to date') }}</x-badge>
                                        @endif
                                    </span>
                                    @if ($a['blocker'])
                                        <span class="d-block small text-warning mt-1">{{ $a['blocker'] }}</span>
                                    @endif
                                    <span class="form-check form-switch d-inline-block mt-2 mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="auto-addon-{{ $m->id }}" @checked($m->auto_update) wire:click="toggleAutoAddon({{ $m->id }})" @disabled($availability !== null)>
                                        <label class="form-check-label small" for="auto-addon-{{ $m->id }}">{{ __('Update automatically') }}</label>
                                    </span>
                                </span>
                                @if ($a['release'] && ! $a['blocker'])
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="installAddon({{ $m->id }})" wire:loading.attr="disabled" wire:target="installAddon({{ $m->id }})">
                                        <span wire:loading wire:target="installAddon({{ $m->id }})" class="spinner-border spinner-border-sm"></span>
                                        {{ __('Update to :version', ['version' => $a['release']['version']]) }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="info" size="18" />
                    <h2 class="card-title">{{ __('How updates work') }}</h2>
                </div>
                <div class="card-body small text-muted">
                    <p>{{ __('LabelDesk checks MusicDesk.tech for new versions twice a day. Every package is checked against the MusicDesk.tech signature before anything is installed.') }}</p>
                    <p>{{ __('After new files are in place — from here, or uploaded by FTP — the database is updated on its own the next time a page is opened. No terminal or SSH is needed.') }}</p>
                    <p class="mb-0">{{ __('Updates are included while your updates period is active.') }}</p>
                </div>
            </div>
            <div class="card">
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-6 fw-normal text-muted">{{ __('Last checked') }}</dt>
                        <dd class="col-6 text-end mb-1">{{ $status['checked_at'] ? \Illuminate\Support\Carbon::parse($status['checked_at'])->diffForHumans() : '—' }}</dd>
                        @if ($lastProgress && in_array($lastProgress['state'], ['done', 'failed'], true))
                            <dt class="col-6 fw-normal text-muted">{{ __('Last update') }}</dt>
                            <dd class="col-6 text-end mb-0">{{ \Illuminate\Support\Carbon::createFromTimestamp($lastProgress['at'])->diffForHumans() }} &middot; {{ $lastProgress['state'] === 'done' ? __('installed') : __('failed') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

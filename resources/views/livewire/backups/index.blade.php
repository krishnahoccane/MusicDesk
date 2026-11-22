<div>
    <x-page-header :title="__('Backups')" :subtitle="__('Copies of your database and uploaded files, stored privately on this server.')" />

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="database-backup" size="18" />
                    <h2 class="card-title">{{ __('Create a backup') }}</h2>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">{{ __('A backup contains every database table plus master audio, artwork, documents and statement files. Server passwords and the application key (.env) are never included, so keep a separate copy of your .env file somewhere safe.') }}</p>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="include-files" wire:model="includeFiles">
                            <label class="form-check-label" for="include-files">{{ __('Include uploaded files') }}</label>
                        </div>
                        <button type="button" class="btn btn-primary ms-auto" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                            <span wire:loading wire:target="create" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            <span wire:loading.remove wire:target="create"><x-icon name="database-backup" size="16" /></span>
                            {{ __('Back up now') }}
                        </button>
                    </div>
                    <div class="small text-muted mt-2" wire:loading wire:target="create">{{ __('Creating the backup. Large catalogues can take a few minutes; keep this page open.') }}</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <x-icon name="archive" size="18" />
                    <h2 class="card-title">{{ __('Stored backups') }}</h2>
                    <span class="small text-muted ms-auto">{{ $directory }}</span>
                </div>
                @if ($backups === [])
                    <x-empty-state icon="archive" :title="__('No backups yet')" :description="__('Create your first backup above, or turn on the nightly backup.')" />
                @else
                    <ul class="ld-list">
                        @foreach ($backups as $backup)
                            <li class="ld-list__item" wire:key="backup-{{ $backup['name'] }}">
                                <span class="ld-list__icon"><x-icon :name="$backup['database_only'] ? 'database' : 'archive'" size="16" /></span>
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-block fw-semibold small text-truncate">{{ $backup['name'] }}</span>
                                    <span class="ld-list__meta">
                                        {{ App\Support\Format::datetime($backup['created_at']) }} &middot; {{ App\Support\Format::bytes($backup['size']) }}
                                        &middot; {{ $backup['database_only'] ? __('Database only') : __('Database and files') }}
                                    </span>
                                </span>
                                <a href="{{ route('backups.download', $backup['name']) }}" class="btn btn-sm btn-light" title="{{ __('Download') }}">
                                    <x-icon name="download" size="15" /> <span class="d-none d-md-inline">{{ __('Download') }}</span>
                                </a>
                                <button type="button" class="btn btn-sm btn-light text-danger" title="{{ __('Delete') }}"
                                        wire:click="delete('{{ $backup['name'] }}')"
                                        wire:confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $backup['name']]) }}">
                                    <x-icon name="trash-2" size="15" /><span class="visually-hidden">{{ __('Delete') }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="calendar-clock" size="18" />
                    <h2 class="card-title">{{ __('Schedule & retention') }}</h2>
                </div>
                <form class="card-body" wire:submit="saveSettings" novalidate>
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="schedule" wire:model="schedule">
                        <label class="form-check-label" for="schedule">{{ __('Back up every night') }}</label>
                    </div>
                    <div class="form-text mb-3">{{ __('Runs automatically once a day, from 02:00, the next time someone uses LabelDesk. No cron job needed.') }}</div>

                    <label class="form-label" for="retention">{{ __('Backups to keep') }}</label>
                    <input type="number" id="retention" min="1" max="90" class="form-control @error('retention') is-invalid @enderror" style="max-width: 140px" wire:model="retention">
                    @error('retention') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text mb-3">{{ __('Older backups are deleted automatically after each new backup.') }}</div>

                    <button type="submit" class="btn btn-outline-primary">{{ __('Save') }}</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <x-icon name="archive-restore" size="18" />
                    <h2 class="card-title">{{ __('Restoring a backup') }}</h2>
                </div>
                <div class="card-body small">
                    <p class="text-muted">{{ __('Restoring replaces all current data, so it is done by hand rather than with a button:') }}</p>
                    <ol class="ps-3 mb-3">
                        <li>{{ __('Download the backup and unzip it on your computer.') }}</li>
                        <li>{{ __('Import database.sql into an empty database with phpMyAdmin (cPanel → phpMyAdmin → Import).') }}</li>
                        <li>{{ __('Upload files/private to storage/app/private and files/public to storage/app/public.') }}</li>
                        <li>{{ __('Use the .env file (with the same APP_KEY) from the original installation.') }}</li>
                    </ol>
                    <p class="text-muted mb-0">{{ __('The full guide is in docs/backups.md.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

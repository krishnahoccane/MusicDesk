<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    @if (session('success')) <x-alert variant="success" class="mb-3">{{ session('success') }}</x-alert> @endif
    @if (session('error')) <x-alert variant="danger" class="mb-3">{{ session('error') }}</x-alert> @endif

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- Destinations --}}
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="cloud-upload" size="18" />
                    <h2 class="card-title">{{ __('Storage destinations') }}</h2>
                    <div class="dropdown ms-auto">
                        <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><x-icon name="plus" size="14" /> {{ __('Add storage') }}</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @foreach ($types as $key => $label)
                                <li><button type="button" class="dropdown-item" wire:click="startAdd('{{ $key }}')">{{ $label }}</button></li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                @if ($targets->isEmpty())
                    <x-empty-state icon="cloud-upload" :title="__('No storage added yet')"
                                   :description="__('Add Google Drive, OneDrive, OneDrive for Business, Dropbox, Zoho WorkDrive or an FTP server. Master audio and artwork are copied to every enabled storage.')" />
                @else
                    <ul class="ld-list">
                        @foreach ($targets as $t)
                            @php($m = $t['model'])
                            <li class="ld-list__item flex-wrap" wire:key="target-{{ $m->id }}">
                                <div class="form-check form-switch mb-0" title="{{ $m->enabled ? __('Enabled') : __('Disabled') }}">
                                    <input class="form-check-input" type="checkbox" role="switch" id="enabled-{{ $m->id }}" @checked($m->enabled) wire:click="toggle({{ $m->id }})" aria-label="{{ __('Enable :name', ['name' => $m->name]) }}">
                                </div>
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="fw-semibold" style="color: var(--ld-heading)">{{ $m->name }}</span>
                                        <x-badge>{{ $t['label'] }}</x-badge>
                                        @if (! $t['connected'])
                                            <x-badge variant="warning">{{ __('not connected') }}</x-badge>
                                        @elseif (! $t['ready'])
                                            <x-badge variant="warning">{{ __('choose a folder') }}</x-badge>
                                        @elseif ($m->enabled)
                                            <x-badge variant="success">{{ __('enabled') }}</x-badge>
                                        @else
                                            <x-badge>{{ __('disabled') }}</x-badge>
                                        @endif
                                    </span>
                                    <span class="ld-list__meta d-block">
                                        {{ $m->account ?: '—' }} &middot; <span class="font-mono">{{ $m->folder_name ?: __('no folder') }}</span>
                                        &middot; {{ __(':up uploaded, :pending queued, :failed failed', ['up' => $t['counts']['uploaded'] ?? 0, 'pending' => ($t['counts']['pending'] ?? 0) + ($t['counts']['waiting'] ?? 0), 'failed' => $t['counts']['failed'] ?? 0]) }}
                                    </span>
                                </span>
                                <span class="d-flex gap-1">
                                    @if ($t['oauth'])
                                        <a href="{{ route('settings.media.connect', $m) }}" class="btn btn-sm btn-ghost">{{ $t['connected'] ? __('Reconnect') : __('Connect') }}</a>
                                    @endif
                                    @if ($t['connected'])
                                        <button type="button" class="btn btn-sm btn-ghost" wire:click="browse({{ $m->id }})">{{ __('Folder') }}</button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-ghost" wire:click="edit({{ $m->id }})">{{ __('Edit') }}</button>
                                    <button type="button" class="btn btn-sm btn-ghost text-danger" wire:click="delete({{ $m->id }})" wire:confirm="{{ __('Remove :name? Files already uploaded stay in that storage.', ['name' => $m->name]) }}" aria-label="{{ __('Remove') }}"><x-icon name="trash-2" size="14" /></button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Add / edit --}}
            @if ($showForm)
                <div class="card mb-3">
                    <div class="card-header">
                        <span class="ld-step">1</span>
                        <h2 class="card-title">{{ $editingId ? __('Edit :type', ['type' => $formLabel]) : __('Add :type', ['type' => $formLabel]) }}</h2>
                        <button type="button" class="btn btn-sm btn-ghost ms-auto" wire:click="cancelForm">{{ __('Cancel') }}</button>
                    </div>
                    <form class="card-body" wire:submit="saveTarget" novalidate>
                        @if ($formOAuth)
                            <ol class="small text-muted ps-3">
                                @switch($type)
                                    @case('google_drive')
                                        <li>{!! __('In <a href=":url" target="_blank" rel="noopener">Google Cloud Console</a>, enable the <strong>Google Drive API</strong>, publish the OAuth consent screen, and create an OAuth client of type "Web application".', ['url' => 'https://console.cloud.google.com/apis/credentials']) !!}</li>
                                        @break
                                    @case('dropbox')
                                        <li>{!! __('In the <a href=":url" target="_blank" rel="noopener">Dropbox App Console</a>, create an app with "Full Dropbox" access and the files.content.read / files.content.write / account_info.read permissions.', ['url' => 'https://www.dropbox.com/developers/apps']) !!}</li>
                                        @break
                                    @case('zoho_workdrive')
                                        <li>{!! __('In the <a href=":url" target="_blank" rel="noopener">Zoho API Console</a> (for your data centre), add a "Server-based application".', ['url' => 'https://api-console.zoho.com/']) !!}</li>
                                        @break
                                    @default
                                        <li>{!! __('In <a href=":url" target="_blank" rel="noopener">Microsoft Entra admin center → App registrations</a>, register an app, add a "Web" platform and create a client secret.', ['url' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade']) !!}</li>
                                @endswitch
                                <li>{{ __('Register this redirect URI (the same one works for every :type you add):', ['type' => $formLabel]) }}</li>
                            </ol>
                            <input class="form-control form-control-sm font-mono mb-3" value="{{ $redirectUri }}" readonly aria-label="{{ __('Redirect URI') }}" onclick="this.select()">
                        @endif

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label" for="target-name">{{ __('Name') }}</label>
                                <input id="target-name" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="80">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @foreach ($fields as $key => $field)
                                <div class="col-md-6" wire:key="field-{{ $type }}-{{ $key }}">
                                    <label class="form-label" for="cred-{{ $key }}">{{ $field['label'] }}</label>
                                    @if ($field['type'] === 'select')
                                        <select id="cred-{{ $key }}" class="form-select @error('creds.'.$key) is-invalid @enderror" wire:model="creds.{{ $key }}">
                                            @foreach ($field['options'] as $value => $optionLabel)
                                                <option value="{{ $value }}">{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input id="cred-{{ $key }}" type="{{ $field['type'] === 'password' ? 'password' : 'text' }}" class="form-control {{ in_array($key, ['client_id', 'host', 'root'], true) ? 'font-mono' : '' }} @error('creds.'.$key) is-invalid @enderror"
                                               wire:model="creds.{{ $key }}" autocomplete="{{ $field['type'] === 'password' ? 'new-password' : 'off' }}"
                                               placeholder="{{ ($field['secret'] ?? false) && $hasSecret($key) ? __('Saved — leave blank to keep') : '' }}">
                                    @endif
                                    @error('creds.'.$key) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    @isset($field['help']) <div class="form-text">{{ $field['help'] }}</div> @endisset
                                </div>
                            @endforeach
                        </div>
                        <button type="submit" class="btn btn-primary mt-3">
                            <x-icon :name="$formOAuth ? 'log-in' : 'plug'" size="16" /> {{ $formOAuth ? __('Save & connect') : __('Save & test connection') }}
                        </button>
                    </form>
                </div>
            @endif

            {{-- Folder picker --}}
            @if ($browsingId)
                <div class="card mb-3">
                    <div class="card-header">
                        <span class="ld-step">2</span>
                        <h2 class="card-title">{{ __('Choose the folder for :name', ['name' => $browsingName]) }}</h2>
                        <button type="button" class="btn btn-sm btn-ghost ms-auto" wire:click="closeBrowser">{{ __('Cancel') }}</button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1 p-2 border-bottom small">
                        @foreach ($path as $i => $crumb)
                            @if (! $loop->first) <x-icon name="chevron-right" size="13" class="text-subtle" /> @endif
                            <button type="button" class="btn btn-sm btn-ghost px-1 py-0" wire:click="up({{ $i }})">{{ $crumb['name'] }}</button>
                        @endforeach
                    </div>
                    <ul class="ld-list" style="max-height: 280px; overflow-y: auto">
                        @forelse ($folders as $folder)
                            <li wire:key="f-{{ md5($folder['id']) }}">
                                <button type="button" class="ld-list__item w-100 border-0 bg-transparent text-start py-2" wire:click="open(@js($folder['id']), @js($folder['name']))">
                                    <x-icon name="folder" size="15" /> <span class="small">{{ $folder['name'] }}</span>
                                    <x-icon name="chevron-right" size="14" class="ms-auto text-subtle" />
                                </button>
                            </li>
                        @empty
                            <li class="p-3 small text-muted">{{ __('No folders here.') }}</li>
                        @endforelse
                    </ul>
                    <div class="d-flex flex-wrap gap-2 p-2 border-top">
                        <div class="flex-grow-1">
                            <input class="form-control form-control-sm @error('newFolder') is-invalid @enderror" wire:model="newFolder" placeholder="{{ __('New folder here') }}" maxlength="120" aria-label="{{ __('New folder name') }}">
                            @error('newFolder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <button type="button" class="btn btn-sm btn-light" wire:click="createFolder"><x-icon name="folder-plus" size="14" /> {{ __('Create') }}</button>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="chooseFolder"><x-icon name="check" size="14" /> {{ __('Use this folder') }}</button>
                    </div>
                </div>
            @endif

            {{-- Shared options --}}
            <div class="card">
                <div class="card-header">
                    <x-icon name="settings" size="18" />
                    <h2 class="card-title">{{ __('Server copies') }}</h2>
                </div>
                <form class="card-body" wire:submit="saveOptions" novalidate x-data="{ del: @entangle('delete_local') }">
                    <p class="small text-muted">{{ __('Each release gets a folder "Release title_UPC" in every enabled storage, with masters named ISRC.wav (or .flac …) and artwork named UPC.jpg (or .png), in their original format.') }}</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="delete_local" wire:model="delete_local" x-model="del">
                        <label class="form-check-label" for="delete_local">{{ __('Delete the file from this server after upload') }}</label>
                    </div>
                    <div class="ms-4 mb-2" x-show="del" x-cloak>
                        <label class="form-label small" for="delete_after_days">{{ __('Days after upload') }}</label>
                        <div class="d-flex align-items-center gap-2">
                            <input id="delete_after_days" type="number" min="0" max="365" class="form-control @error('delete_after_days') is-invalid @enderror" style="max-width: 110px" wire:model="delete_after_days">
                            <span class="small text-muted">{{ __('0 = 12 hours after upload') }}</span>
                        </div>
                        @error('delete_after_days') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        <div class="form-text">{{ __('A server copy is only removed once every enabled storage holds a verified copy, checked again just before deleting. Removed files still play and download (streamed from a storage) but are no longer in server backups.') }}</div>
                    </div>
                    <p class="small text-muted" x-show="! del">{{ __('Files are kept on this server and in every enabled storage.') }}</p>
                    <button type="submit" class="btn btn-primary mt-2">{{ __('Save') }}</button>
                </form>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <x-icon name="activity" size="18" />
                    <h2 class="card-title">{{ __('Status') }}</h2>
                </div>
                <div class="card-body">
                    <dl class="ld-dl mb-3">
                        <dt>{{ __('Uploaded') }}</dt><dd class="font-mono">{{ $totals['uploaded'] ?? 0 }}</dd>
                        <dt>{{ __('Queued') }}</dt><dd class="font-mono">{{ $totals['pending'] ?? 0 }}</dd>
                        <dt>{{ __('Waiting for codes') }}</dt><dd class="font-mono">{{ $totals['waiting'] ?? 0 }}</dd>
                        <dt>{{ __('Failed') }}</dt><dd class="font-mono">{{ $totals['failed'] ?? 0 }}</dd>
                    </dl>

                    @foreach ($problems as $problem)
                        <div class="small mb-2">
                            <x-badge :variant="$problem->status === 'failed' ? 'danger' : 'warning'">{{ $problem->status }}</x-badge>
                            <span class="fw-semibold">{{ $problem->track?->title ?? $problem->release?->title }}</span>
                            <span class="text-muted">&middot; {{ $problem->target?->name }}</span>
                            <span class="d-block text-muted">{{ $problem->last_error }}</span>
                        </div>
                    @endforeach

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-light" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">
                            <span wire:loading wire:target="syncNow" class="spinner-border spinner-border-sm"></span> {{ __('Sync now') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost" wire:click="queueAll">{{ __('Queue all existing files') }}</button>
                    </div>
                    <p class="small text-muted mt-3 mb-0">{{ __('Uploads run automatically in the background every 5 minutes while LabelDesk is in use. No cron job needed.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

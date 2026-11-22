<div>
    <x-page-header :title="__('Add-ons')" :subtitle="__('Extend :product with add-ons from MusicDesk.tech.', ['product' => config('labeldesk.product.name')])">
        <x-slot:actions>
            <a href="{{ $addonsUrl }}" target="_blank" rel="noopener" class="btn btn-light"><x-icon name="external-link" size="16" /> {{ __('Get add-ons') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success')) <x-alert variant="success" class="mb-3">{{ session('success') }}</x-alert> @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <x-icon name="puzzle" size="18" />
                    <h2 class="card-title">{{ __('Installed add-ons') }}</h2>
                </div>

                @if ($plugins->isEmpty())
                    <x-empty-state icon="puzzle" :title="__('No add-ons installed')"
                                   :description="__('Download an add-on from your MusicDesk.tech account, then upload the .zip file here.')" />
                @else
                    <ul class="ld-list">
                        @foreach ($plugins as $p)
                            @php($m = $p['model'])
                            <li class="ld-list__item flex-wrap align-items-start" wire:key="plugin-{{ $m->id }}">
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="fw-semibold" style="color: var(--ld-heading)">{{ $m->name }}</span>
                                        <span class="font-mono small text-muted">v{{ $m->version }}</span>
                                        @if ($p['missing'])
                                            <x-badge variant="danger">{{ __('files missing') }}</x-badge>
                                        @elseif (! $p['licensed'])
                                            <x-badge variant="warning">{{ $m->license_key ? __('key not valid') : __('needs license key') }}</x-badge>
                                        @elseif ($m->active)
                                            <x-badge variant="success">{{ __('active') }}</x-badge>
                                        @else
                                            <x-badge>{{ __('inactive') }}</x-badge>
                                        @endif
                                    </span>
                                    @if ($m->description)
                                        <span class="d-block small text-muted mt-1">{{ $m->description }}</span>
                                    @endif
                                    @if ($m->license_key)
                                        <span class="d-block small text-muted mt-1">
                                            {{ __('License key') }} <span class="font-mono">{{ $m->maskedKey() }}</span>
                                            &middot; {{ $m->license_status ?? '—' }}
                                            @if ($m->license_expires_at) &middot; {{ __('until :date', ['date' => $m->license_expires_at->format(config('labeldesk.date_format', 'd M Y'))]) }} @endif
                                            @if ($m->license_checked_at) &middot; {{ __('checked :time', ['time' => $m->license_checked_at->diffForHumans()]) }} @endif
                                        </span>
                                    @endif
                                    @if ($m->active && ! $p['licensed'])
                                        <span class="d-block small text-warning mt-1">{{ __('Paused: the license key for this add-on is not valid any more. Its data is kept.') }}</span>
                                    @endif
                                    @if ($m->license_error)
                                        <span class="d-block small text-danger mt-1">{{ $m->license_error }}</span>
                                    @endif
                                    @if (! $m->active && ! $p['missing'])
                                        <span class="d-flex flex-wrap gap-2 mt-2">
                                            @unless ($p['licensed'])
                                                <span>
                                                    <input class="form-control form-control-sm font-mono @error('keys.'.$m->id) is-invalid @enderror" style="min-width: 260px"
                                                           wire:model="keys.{{ $m->id }}" placeholder="{{ __('Add-on license key') }}" autocomplete="off" aria-label="{{ __('License key for :name', ['name' => $m->name]) }}">
                                                    @error('keys.'.$m->id) <span class="invalid-feedback d-block">{{ $message }}</span> @enderror
                                                </span>
                                            @endunless
                                        </span>
                                    @endif
                                    @if ($m->last_error)
                                        <span class="d-block small text-danger mt-1">{{ $m->last_error }}</span>
                                    @endif
                                </span>
                                <span class="d-flex gap-1">
                                    @if ($m->active)
                                        <button type="button" class="btn btn-sm btn-light" wire:click="deactivate({{ $m->id }})" wire:loading.attr="disabled">{{ __('Deactivate') }}</button>
                                    @else
                                        <button type="button" class="btn btn-sm btn-primary" wire:click="activate({{ $m->id }})" wire:loading.attr="disabled" @disabled($p['missing'])>{{ __('Activate') }}</button>
                                        @if ($m->license_key)
                                            <button type="button" class="btn btn-sm btn-ghost" wire:click="removeKey({{ $m->id }})" wire:confirm="{{ __('Remove the license key from :name? This domain is released on the key.', ['name' => $m->name]) }}">{{ __('Remove key') }}</button>
                                        @endif
                                        <button type="button" class="btn btn-sm btn-ghost text-danger" wire:click="delete({{ $m->id }})"
                                                wire:confirm="{{ __('Delete :name? Its files are removed; its data stays in the database.', ['name' => $m->name]) }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="14" /></button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="upload" size="18" />
                    <h2 class="card-title">{{ __('Upload add-on') }}</h2>
                </div>
                <form class="card-body" wire:submit="upload" novalidate>
                    <p class="small text-muted">{{ __('Upload the .zip file downloaded from MusicDesk.tech. Its signature is checked before anything is installed; uploading a newer version updates the add-on.') }}</p>
                    @unless ($writable)
                        <x-alert variant="warning" class="mb-2">{{ __('The plugins folder is not writable: :path', ['path' => $path]) }}</x-alert>
                    @endunless
                    <input type="file" accept=".zip,application/zip" class="form-control @error('package') is-invalid @enderror" wire:model="package" aria-label="{{ __('Add-on file') }}">
                    @error('package') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div wire:loading wire:target="package" class="small text-muted mt-1">{{ __('Uploading…') }}</div>
                    <button type="submit" class="btn btn-primary mt-3" wire:loading.attr="disabled" wire:target="upload,package">
                        <span wire:loading wire:target="upload" class="spinner-border spinner-border-sm"></span>
                        <x-icon name="upload" size="16" /> {{ __('Install') }}
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <x-icon name="key-round" size="18" />
                    <h2 class="card-title">{{ __('Add-on license keys') }}</h2>
                </div>
                <div class="card-body small text-muted">
                    {{ __('Every add-on has its own license key. Buy the add-on at MusicDesk.tech, download it, upload it here and enter its key to activate it. Keys are checked with MusicDesk.tech every hour.') }}
                </div>
            </div>
        </div>
    </div>
</div>

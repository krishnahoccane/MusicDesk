<div>
    <x-page-header :title="__('Webhooks')" :subtitle="__('Signed URLs your distributor or stores call to report delivery status and other events.')">
        <x-slot:actions>
            <a href="{{ route('incoming-webhooks.deliveries') }}" class="btn btn-light" wire:navigate><x-icon name="history" size="16" /> {{ __('Delivery log') }}</a>
            @unless ($showForm)
                <button type="button" class="btn btn-primary" wire:click="create"><x-icon name="plus" size="16" /> {{ __('Add endpoint') }}</button>
            @endunless
        </x-slot:actions>
    </x-page-header>

    @if ($revealedSecret)
        @php($revealed = $endpoints->firstWhere('id', $revealedFor))
        <x-alert variant="warning" :title="__('Copy the signing secret now')" class="mb-3">
            <p class="mb-2">{{ __('This is the only time the secret for ":name" is shown. Give it to the sender together with the URL; store it in their secret manager.', ['name' => $revealed?->name]) }}</p>
            <div class="row g-2 mb-2" x-data="{ copied: null }">
                <div class="col-lg-6">
                    <label class="form-label small mb-1" for="revealed-url">{{ __('URL') }}</label>
                    <div class="input-group">
                        <input id="revealed-url" type="text" class="form-control font-monospace" readonly value="{{ $revealed?->url() }}" x-on:focus="$el.select()">
                        <button type="button" class="btn btn-light" x-on:click="navigator.clipboard.writeText(document.getElementById('revealed-url').value); copied = 'url'">
                            <x-icon name="copy" size="16" /> <span x-text="copied === 'url' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                        </button>
                    </div>
                </div>
                <div class="col-lg-6">
                    <label class="form-label small mb-1" for="revealed-secret">{{ __('Signing secret') }}</label>
                    <div class="input-group">
                        <input id="revealed-secret" type="text" class="form-control font-monospace" readonly value="{{ $revealedSecret }}" x-on:focus="$el.select()" autocomplete="off">
                        <button type="button" class="btn btn-light" x-on:click="navigator.clipboard.writeText(document.getElementById('revealed-secret').value); copied = 'secret'">
                            <x-icon name="copy" size="16" /> <span x-text="copied === 'secret' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-primary" wire:click="dismissSecret">{{ __('I have saved the secret') }}</button>
        </x-alert>
    @endif

    @if ($showForm)
        <form wire:submit="save" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="webhook" size="18" class="text-muted" />
                <h2 class="card-title">{{ $editingId ? __('Edit endpoint') : __('Add endpoint') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="wh-name" class="form-label">{{ __('Name') }}</label>
                        <input id="wh-name" type="text" wire:model="name" maxlength="100" placeholder="{{ __('e.g. Distributor status updates') }}" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="wh-action" class="form-label">{{ __('What it does') }}</label>
                        <select id="wh-action" wire:model.live="action" @class(['form-select', 'is-invalid' => $errors->has('action')])>
                            @foreach ($actions as $key => $handler)
                                <option value="{{ $key }}">{{ $handler->label() }}</option>
                            @endforeach
                        </select>
                        @error('action') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @isset($actions[$action]) <div class="form-text">{{ $actions[$action]->description() }}</div> @endisset
                    </div>

                    @if ($action === 'delivery_status')
                        <div class="col-md-6">
                            <label for="wh-user" class="form-label">{{ __('Acts as') }}</label>
                            <select id="wh-user" wire:model="acting_user_id" @class(['form-select', 'is-invalid' => $errors->has('acting_user_id')])>
                                <option value="">{{ __('Choose a user') }}</option>
                                @foreach ($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Status changes are recorded in the delivery history and activity log under this user. Needs the “Manage deliveries and takedowns” permission.') }}</div>
                            @error('acting_user_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="wh-dsp" class="form-label">{{ __('Default store') }}</label>
                            <select id="wh-dsp" wire:model="default_dsp_id" @class(['form-select', 'is-invalid' => $errors->has('default_dsp_id')])>
                                <option value="">{{ __('None: the payload must name the store') }}</option>
                                @foreach ($dsps as $dsp)
                                    <option value="{{ $dsp->id }}">{{ $dsp->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Used when a payload names no store (e.g. a store that reports only its own status).') }}</div>
                            @error('default_dsp_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif

                    <div class="col-sm-6 col-md-3">
                        <label for="wh-tolerance" class="form-label">{{ __('Time window') }}</label>
                        <div class="input-group">
                            <input id="wh-tolerance" type="number" min="60" max="3600" wire:model="tolerance_seconds" @class(['form-control', 'is-invalid' => $errors->has('tolerance_seconds')])>
                            <span class="input-group-text">{{ __('seconds') }}</span>
                        </div>
                        <div class="form-text">{{ __('Signed requests older than this are refused (replay protection).') }}</div>
                        @error('tolerance_seconds') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-9">
                        <label for="wh-ips" class="form-label">{{ __('IP allow-list') }} <span class="text-muted fw-normal">({{ __('optional') }})</span></label>
                        <textarea id="wh-ips" rows="2" wire:model="allowed_ips" maxlength="5000" placeholder="203.0.113.10&#10;198.51.100.0/24" @class(['form-control font-monospace', 'is-invalid' => $errors->has('allowed_ips')])></textarea>
                        <div class="form-text">{{ __('One IP address or range per line. Leave empty to accept signed requests from anywhere.') }}</div>
                        @error('allowed_ips') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <details @if ($errors->has('fields.*')) open @endif>
                            <summary class="fw-semibold">{{ __('Payload fields') }}</summary>
                            <p class="small text-muted mt-2 mb-2">{{ __('Where each value is found in the JSON body. Leave a field empty for the default; use dots for nested fields (data.status).') }}</p>
                            <div class="row g-2">
                                @foreach ($fields as $field => $value)
                                    @if ($action === 'delivery_status' || $field === 'event_id')
                                        <div class="col-sm-6 col-lg-4" wire:key="field-{{ $field }}">
                                            <label for="wh-field-{{ $field }}" class="form-label small mb-1">{{ match ($field) {
                                                'event_id' => __('Event id'), 'upc' => __('UPC'), 'isrc' => __('ISRC'), 'store' => __('Store name'),
                                                'status' => __('Status'), 'date' => __('Date (YYYY-MM-DD)'), 'store_id' => __('Store’s release id'),
                                                'store_url' => __('Store link'), 'reason' => __('Reason / comment'), default => $field } }}</label>
                                            <input id="wh-field-{{ $field }}" type="text" wire:model="fields.{{ $field }}" maxlength="100" placeholder="{{ $defaults[$field] ?? '' }}"
                                                   @class(['form-control form-control-sm font-monospace', 'is-invalid' => $errors->has('fields.'.$field)])>
                                            @error('fields.'.$field) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="wh-active" wire:model="is_active">
                            <label class="form-check-label" for="wh-active">{{ __('Enabled (disabled endpoints answer 404)') }}</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ $editingId ? __('Save') : __('Create endpoint') }}</button>
            </div>
        </form>
    @endif

    <div class="card mb-3">
        @if ($endpoints->isEmpty())
            <x-empty-state icon="webhook" :title="__('No endpoints yet')" :description="__('Add an endpoint to get a signed URL your distributor or store can send status updates to.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Endpoint') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('URL') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Last 24 hours') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($endpoints as $endpoint)
                            <tr wire:key="endpoint-{{ $endpoint->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $endpoint->name }}</div>
                                    <div class="small text-muted">
                                        {{ isset($actions[$endpoint->action]) ? $actions[$endpoint->action]->label() : $endpoint->action }}
                                        @if ($endpoint->action === 'delivery_status')
                                            · {{ __('acts as :name', ['name' => $endpoint->actingUser?->name ?? __('nobody')]) }}
                                        @endif
                                    </div>
                                    @if ($endpoint->hasPreviousSecret())
                                        <div class="small text-warning">{{ __('Old secret still accepted until :time', ['time' => App\Support\Format::datetime($endpoint->previous_secret_expires_at)]) }}</div>
                                    @endif
                                </td>
                                <td class="d-none d-lg-table-cell" style="max-width: 22rem" x-data="{ copied: false }">
                                    <div class="d-flex align-items-center gap-1">
                                        <code class="small text-truncate">{{ $endpoint->url() }}</code>
                                        <button type="button" class="ld-icon-btn" x-on:click="navigator.clipboard.writeText(@js($endpoint->url())); copied = true" aria-label="{{ __('Copy URL') }}">
                                            <x-icon x-show="!copied" name="copy" size="14" /><x-icon x-show="copied" x-cloak name="check" size="14" />
                                        </button>
                                    </div>
                                </td>
                                <td class="d-none d-md-table-cell small">
                                    <a href="{{ route('incoming-webhooks.deliveries', ['endpoint' => $endpoint->id]) }}" wire:navigate>{{ trans_choice('{0} No requests|{1} 1 request|[2,*] :count requests', $endpoint->recent_count, ['count' => $endpoint->recent_count]) }}</a>
                                    @if ($endpoint->recent_failed_count > 0)
                                        <span class="text-danger">· {{ trans_choice('{1} 1 failed|[2,*] :count failed', $endpoint->recent_failed_count, ['count' => $endpoint->recent_failed_count]) }}</span>
                                    @endif
                                    <div class="text-muted">{{ __('Last: :time', ['time' => App\Support\Format::datetime($endpoint->last_delivery_at, __('never'))]) }}</div>
                                </td>
                                <td><x-badge :variant="$endpoint->is_active ? 'success' : null">{{ $endpoint->is_active ? __('Enabled') : __('Disabled') }}</x-badge></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $endpoint->id }})" aria-label="{{ __('Edit :name', ['name' => $endpoint->name]) }}"><x-icon name="pencil" size="14" /></button>
                                    <button type="button" class="btn btn-sm btn-light" wire:click="toggleActive({{ $endpoint->id }})">{{ $endpoint->is_active ? __('Disable') : __('Enable') }}</button>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">{{ __('Secret') }}</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button type="button" class="dropdown-item" wire:click="rotate({{ $endpoint->id }})"
                                                        wire:confirm="{{ __('Create a new secret for :name? The old one keeps working for :hours hours.', ['name' => $endpoint->name, 'hours' => $graceHours]) }}">{{ __('Rotate (old secret works :hours more hours)', ['hours' => $graceHours]) }}</button></li>
                                            <li><button type="button" class="dropdown-item text-danger" wire:click="rotate({{ $endpoint->id }}, true)"
                                                        wire:confirm="{{ __('Create a new secret for :name and stop the old one now? Requests signed with the old secret will be refused.', ['name' => $endpoint->name]) }}">{{ __('Rotate and stop old secret now') }}</button></li>
                                            @if ($endpoint->hasPreviousSecret())
                                                <li><button type="button" class="dropdown-item" wire:click="revokePrevious({{ $endpoint->id }})">{{ __('Stop old secret now') }}</button></li>
                                            @endif
                                        </ul>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $endpoint->id }})"
                                            wire:confirm="{{ __('Delete :name and its delivery log? The sender will get 404 from now on.', ['name' => $endpoint->name]) }}" aria-label="{{ __('Delete :name', ['name' => $endpoint->name]) }}"><x-icon name="trash-2" size="14" /></button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <x-icon name="shield-check" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('How senders sign requests') }}</h2>
        </div>
        <div class="card-body small">
            <p>{{ __('Send a POST with a JSON body to the endpoint URL and these headers:') }}</p>
            <dl class="ld-dl mb-3">
                <dt><code>X-Webhook-Signature</code></dt>
                <dd><code>t=&lt;unix time&gt;,v1=&lt;hex HMAC-SHA256 of "&lt;unix time&gt;.&lt;raw body&gt;" with the signing secret&gt;</code></dd>
                <dt><code>X-Webhook-Id</code></dt>
                <dd>{{ __('Optional unique event id (or an "id" field in the body). Events already processed are answered with 200 "duplicate" and not applied twice; failed events may be sent again.') }}</dd>
            </dl>
            <p class="mb-2">{{ __('Example body for store delivery status:') }}</p>
            <pre class="p-2 rounded mb-3" style="background: var(--ld-surface-hover)"><code>{"id": "evt_1001", "upc": "0123456789012", "store": "Spotify", "status": "live",
 "date": "2026-11-22", "store_release_id": "4uLU6hMC", "store_url": "https://open.spotify.com/album/4uLU6hMC"}</code></pre>
            <p class="mb-0 text-muted">{{ __('Statuses: delivered, live, rejected, takedown_requested, taken_down. Responses: 202 accepted (processed right after), 200 duplicate, 400 invalid JSON, 401 bad signature, 403 IP not allowed, 404 unknown or disabled, 413 too large, 429 too many requests, 503 try again later.') }}</p>
        </div>
    </div>
</div>

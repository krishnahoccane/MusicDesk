<x-layouts.app :title="__('License')" :breadcrumbs="[['label' => __('Settings')], ['label' => __('License information')]]">
    <x-page-header :title="__('License information')" :subtitle="__('Your LabelDesk license is issued and verified by MusicDesk.tech.')">
        @if ($activation && auth()->user()->can('license.manage'))
            <x-slot:actions>
                <form method="POST" action="{{ route('license.verify') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary"><x-icon name="refresh-cw" size="16" /> {{ __('Verify now') }}</button>
                </form>
            </x-slot:actions>
        @endif
    </x-page-header>

    @can('settings.manage')
        @include('livewire.settings._tabs')
    @endcan

    @if ($driver === 'mock')
        <x-alert variant="warning" class="mb-4" :title="__('Development license provider')">{{ __('This installation uses the development mock provider and cannot be used in production.') }}</x-alert>
    @endif

    @if ($snapshot->reason)
        <x-alert :variant="$snapshot->state->variant()" class="mb-4" :title="$snapshot->state->label()">{{ $snapshot->reason }}</x-alert>
    @endif

    @if ($domainChanged && auth()->user()->can('license.manage'))
        <x-alert variant="warning" class="mb-4" :title="__('Domain changed')">
            {{ __('This license is activated for :old but LabelDesk now runs at :new. Transfer the activation below after moving hosting.', ['old' => $activation->domain, 'new' => $currentDomain]) }}
        </x-alert>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <x-icon name="key-round" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('License details') }}</h2>
                    <x-badge :variant="$snapshot->state->variant()" class="ms-auto">{{ $snapshot->state->label() }}</x-badge>
                </div>
                <div class="card-body py-2">
                    @if ($activation)
                        @php($fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->setTimezone(config('labeldesk.display_timezone'))->format(config('labeldesk.datetime_format')) : '—')
                        @php($support = $activation->support_until)
                        <dl class="ld-dl">
                            <dt>{{ __('Product') }}</dt><dd>{{ config('labeldesk.product.name') }} {{ config('labeldesk.product.version') }}</dd>
                            <dt>{{ __('License key') }}</dt><dd class="font-monospace">{{ $activation->maskedKey() }}</dd>
                            <dt>{{ __('Plan') }}</dt><dd class="text-capitalize">{{ $activation->plan ?? '—' }}</dd>
                            <dt>{{ __('License status') }}</dt><dd class="text-capitalize">{{ $activation->license_status ?? '—' }}</dd>
                            <dt>{{ __('Installation domain') }}</dt><dd>{{ $activation->domain }} @if ($activation->environment) <x-badge :variant="$activation->environment === 'staging' ? 'info' : 'secondary'">{{ $activation->environment === 'staging' ? __('Staging') : __('Production') }}</x-badge> @endif</dd>
                            <dt>{{ __('Activation type') }}</dt><dd>{{ $activation->offline ? __('Offline activation file') : __('Online') }}</dd>
                            <dt>{{ __('Updates & support until') }}</dt><dd>
                                {{ $support ? App\Support\Format::date($support) : '—' }}
                                @if ($support && $support->isPast()) <x-badge variant="warning">{{ __('Ended') }}</x-badge> @endif
                            </dd>
                            @if ($activation->max_version)
                                <dt>{{ __('Latest allowed version') }}</dt><dd>{{ $activation->max_version }}</dd>
                            @endif
                            <dt>{{ __('User allowance') }}</dt><dd>{{ $activation->user_limit ?? __('Unlimited') }}</dd>
                            <dt>{{ __('Token valid until') }}</dt><dd>{{ $fmt($snapshot->result?->validUntil) }}</dd>
                            <dt>{{ __('Last verification') }}</dt><dd>{{ $fmt($activation->last_verified_at) }}</dd>
                        </dl>
                        @foreach ((array) $activation->messages as $notice)
                            <x-alert variant="info" class="mb-2">{{ $notice }}</x-alert>
                        @endforeach
                    @else
                        <x-empty-state icon="key-round" :title="__('No active license')" :description="__('Activate a license key from your MusicDesk.tech account to unlock LabelDesk.')" />
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="sparkles" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Enabled features') }}</h2>
                </div>
                <ul class="list-unstyled mb-0 px-4 py-2">
                    @foreach ($features as $key => $feature)
                        <li class="d-flex align-items-center gap-2 py-1">
                            <x-icon :name="$feature['enabled'] ? 'circle-check-big' : 'circle-minus'" size="16" @class([$feature['enabled'] ? 'text-success' : 'text-subtle']) />
                            <span @class(['text-subtle' => ! $feature['enabled']])>{{ $feature['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card">
                <div class="card-header">
                    <x-icon name="life-buoy" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Support') }}</h2>
                </div>
                <div class="card-body small">
                    <p class="text-muted">{{ __('Manage your licenses, renewals and activations in your MusicDesk account.') }}</p>
                    <a href="{{ config('licensing.customer_portal_url') }}" target="_blank" rel="noopener" class="d-block mb-1"><x-icon name="external-link" size="14" /> {{ __('MusicDesk customer account') }}</a>
                    <a href="{{ config('licensing.public_support_url') }}" target="_blank" rel="noopener" class="d-block"><x-icon name="external-link" size="14" /> {{ __('MusicDesk support') }}</a>
                </div>
            </div>
        </div>

        @can('license.manage')
            @if (! $activation)
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><x-icon name="key-round" size="18" class="text-muted" /><h2 class="card-title">{{ __('Activate a license') }}</h2></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('license.activate') }}" class="row g-2 align-items-start" novalidate>
                                @csrf
                                <div class="col-md-6">
                                    <input name="license_key" class="form-control font-monospace text-uppercase @error('license_key') is-invalid @enderror" placeholder="LDSK-XXXX-XXXX-XXXX-XXXX" value="{{ old('license_key') }}" maxlength="64" autocomplete="off" aria-label="{{ __('License key') }}">
                                    @error('license_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-auto">
                                    <button type="submit" class="btn btn-primary">{{ __('Activate for :domain', ['domain' => $currentDomain]) }}</button>
                                </div>
                            </form>

                            <details class="mt-4" @if ($errors->has('license_file')) open @endif>
                                <summary class="fw-semibold">{{ __('No internet access on this server? Activate offline') }}</summary>
                                <ol class="small text-muted mt-3 mb-3">
                                    <li>{{ __('Download the activation request file for :domain.', ['domain' => $currentDomain]) }}</li>
                                    <li>{{ __('Upload it in your MusicDesk account under Licenses → Offline activation.') }}</li>
                                    <li>{{ __('Upload the .ldlic file you receive here. It is valid for one year and must be renewed the same way.') }}</li>
                                </ol>
                                <a href="{{ route('license.request-file') }}" class="btn btn-light mb-3"><x-icon name="download" size="16" /> {{ __('Download request file') }}</a>
                                <form method="POST" action="{{ route('license.offline') }}" enctype="multipart/form-data" class="row g-2 align-items-start" novalidate>
                                    @csrf
                                    <div class="col-md-6">
                                        <input type="file" name="license_file" accept=".ldlic,application/json" class="form-control @error('license_file') is-invalid @enderror" aria-label="{{ __('Activation file') }}">
                                        @error('license_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-auto"><button type="submit" class="btn btn-primary">{{ __('Upload activation file') }}</button></div>
                                </form>
                            </details>
                        </div>
                    </div>
                </div>
            @else
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><x-icon name="arrow-right-left" size="18" class="text-muted" /><h2 class="card-title">{{ __('Move to a new domain') }}</h2></div>
                        <div class="card-body">
                            <p class="small text-muted">{{ __('After moving LabelDesk to new hosting or a new domain, update the application URL (APP_URL) and transfer here. The old domain is deactivated and the license is activated for the new one. Offline activations are moved by creating a new request file.') }}</p>
                            @if ($domainChanged)
                                <form method="POST" action="{{ route('license.transfer') }}" class="row g-2">
                                    @csrf
                                    <div class="col-sm-7">
                                        <input type="password" name="password" class="form-control @error('transfer_password') is-invalid @enderror" placeholder="{{ __('Confirm your password') }}" autocomplete="current-password" required aria-label="{{ __('Password') }}">
                                        @error('transfer_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-sm-5"><button type="submit" class="btn btn-primary w-100">{{ __('Transfer to :domain', ['domain' => $currentDomain]) }}</button></div>
                                </form>
                            @else
                                <p class="small mb-0">{{ __('The activation matches the current domain (:domain). No transfer needed.', ['domain' => $currentDomain]) }}</p>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><x-icon name="power" size="18" class="text-muted" /><h2 class="card-title">{{ __('Deactivate installation') }}</h2></div>
                        <div class="card-body">
                            <p class="small text-muted">{{ __('Releases this installation\'s activation slot so the key can be used elsewhere. LabelDesk becomes read-only here; no data is deleted.') }}</p>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deactivate-modal">{{ __('Deactivate…') }}</button>
                        </div>
                    </div>
                </div>
            @endif
        @endcan

        @if ($events->isNotEmpty())
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><x-icon name="history" size="18" class="text-muted" /><h2 class="card-title">{{ __('Recent license activity') }}</h2></div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>{{ __('When') }}</th><th>{{ __('Event') }}</th><th>{{ __('Result') }}</th><th>{{ __('By') }}</th><th>{{ __('Details') }}</th></tr></thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <tr>
                                        <td class="text-nowrap">{{ $event->created_at?->setTimezone(config('labeldesk.display_timezone'))->format(config('labeldesk.datetime_format')) }}</td>
                                        <td class="text-capitalize">{{ str_replace('_', ' ', $event->event) }}</td>
                                        <td><x-badge :variant="$event->result === 'success' ? 'success' : 'danger'">{{ $event->result }}</x-badge></td>
                                        <td>{{ $event->user?->name ?? __('System') }}</td>
                                        <td class="small text-muted">{{ $event->message ?? $event->code }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($activation)
        @can('license.manage')
            <div class="modal fade" id="deactivate-modal" tabindex="-1" aria-labelledby="deactivate-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="POST" action="{{ route('license.deactivate') }}" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="deactivate-title">{{ __('Deactivate this installation?') }}</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p>{{ __('LabelDesk will become read-only on this server until a license is activated again. Your data is kept.') }}</p>
                            <label class="form-label" for="deactivate-password">{{ __('Confirm your password') }}</label>
                            <input id="deactivate-password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="current-password">
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-danger">{{ __('Deactivate') }}</button>
                        </div>
                    </form>
                </div>
            </div>
            @if ($errors->has('password'))
                <script>
                    document.addEventListener('DOMContentLoaded', () => window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('deactivate-modal')).show());
                </script>
            @endif
        @endcan
    @endif
</x-layouts.app>

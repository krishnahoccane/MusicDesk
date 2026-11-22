<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center">
                    <x-icon name="fingerprint" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Identity providers') }}</h2>
                    @unless ($showForm)
                        <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="create">
                            <x-icon name="plus" size="14" /> {{ __('Add provider') }}
                        </button>
                    @endunless
                </div>

                @if ($this->providers->isEmpty() && ! $showForm)
                    <div class="card-body">
                        <x-empty-state icon="fingerprint" :title="__('No identity providers yet')"
                                       :description="__('Add Microsoft Entra ID, Google Workspace, Okta or another OpenID Connect provider so your team can sign in with their work account.')" />
                    </div>
                @elseif ($this->providers->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Provider') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Allowed domains') }}</th>
                                    <th class="d-none d-md-table-cell">{{ __('Linked users') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->providers as $provider)
                                    <tr wire:key="sso-provider-{{ $provider->id }}">
                                        <td>
                                            <span class="fw-semibold">{{ $provider->name }}</span>
                                            <div class="small text-muted">{{ $presets[$provider->preset] ?? $provider->preset }}</div>
                                            <div class="small text-muted text-break"><span class="visually-hidden">{{ __('Redirect URI') }}:</span><code>{{ route('sso.callback', $provider) }}</code></div>
                                        </td>
                                        <td class="d-none d-md-table-cell small">{{ $provider->domains() ? implode(', ', $provider->domains()) : __('Any') }}</td>
                                        <td class="d-none d-md-table-cell">{{ $provider->identities_count }}</td>
                                        <td>
                                            @if ($provider->enabled)
                                                <x-badge variant="success">{{ __('Enabled') }}</x-badge>
                                            @else
                                                <x-badge>{{ __('Disabled') }}</x-badge>
                                            @endif
                                            @if ($provider->jit_enabled)
                                                <x-badge variant="info" class="ms-1">{{ __('Creates accounts') }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-light" wire:click="test({{ $provider->id }})" wire:loading.attr="disabled" wire:target="test({{ $provider->id }})"
                                                    aria-label="{{ __('Test connection to :name', ['name' => $provider->name]) }}" title="{{ __('Test connection') }}"><x-icon name="plug-zap" size="14" /></button>
                                            <button type="button" class="btn btn-sm btn-light" wire:click="toggle({{ $provider->id }})"
                                                    aria-label="{{ $provider->enabled ? __('Disable :name', ['name' => $provider->name]) : __('Enable :name', ['name' => $provider->name]) }}"
                                                    title="{{ $provider->enabled ? __('Disable') : __('Enable') }}"><x-icon :name="$provider->enabled ? 'circle-pause' : 'circle-play'" size="14" /></button>
                                            <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $provider->id }})" aria-label="{{ __('Edit :name', ['name' => $provider->name]) }}"><x-icon name="pencil" size="14" /></button>
                                            <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $provider->id }})"
                                                    wire:confirm="{{ __('Remove :name? Users linked to it sign in with their password or another provider.', ['name' => $provider->name]) }}"
                                                    aria-label="{{ __('Remove :name', ['name' => $provider->name]) }}"><x-icon name="trash-2" size="14" /></button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($showForm)
                <form wire:submit="save" class="card mb-3" novalidate>
                    <div class="card-header">
                        <x-icon name="key-round" size="18" class="text-muted" />
                        <h2 class="card-title">{{ $editingId ? __('Edit provider') : __('Add provider') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="sso-preset" class="form-label">{{ __('Provider type') }}</label>
                                <select id="sso-preset" wire:model.live="preset" @class(['form-select', 'is-invalid' => $errors->has('preset')]) @disabled($editingId)>
                                    @foreach ($presets as $value => $label)
                                        <option value="{{ $value }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                @error('preset') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="sso-name" class="form-label">{{ __('Button name') }}</label>
                                <input id="sso-name" type="text" wire:model.blur="name" maxlength="80" placeholder="{{ __('Microsoft') }}"
                                       @class(['form-control', 'is-invalid' => $errors->has('name')])>
                                <div class="form-text">{{ __('Shown as "Sign in with …" on the sign-in page.') }}</div>
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            @if ($preset === 'entra')
                                <div class="col-12">
                                    <label for="sso-tenant" class="form-label">{{ __('Directory (tenant) ID') }}</label>
                                    <input id="sso-tenant" type="text" wire:model="tenant" maxlength="36" autocomplete="off" placeholder="00000000-0000-0000-0000-000000000000"
                                           @class(['form-control font-monospace', 'is-invalid' => $errors->has('tenant')])>
                                    <div class="form-text">{{ __('Entra admin center → App registrations → your app → Overview. Add the optional ID-token claims "email" and "xms_edov" so LabelDesk can trust the email address.') }}</div>
                                    @error('tenant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @elseif ($preset === 'okta' || $preset === 'oidc')
                                <div class="col-12">
                                    <label for="sso-issuer" class="form-label">{{ __('Issuer URL') }}</label>
                                    <input id="sso-issuer" type="url" wire:model="issuer" maxlength="240" autocomplete="off"
                                           placeholder="{{ $preset === 'okta' ? 'https://your-org.okta.com/oauth2/default' : 'https://id.example.com' }}"
                                           @class(['form-control', 'is-invalid' => $errors->has('issuer')])>
                                    <div class="form-text">{{ __('LabelDesk reads the configuration from <issuer>/.well-known/openid-configuration.') }}</div>
                                    @error('issuer') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @else
                                <div class="col-12 small text-muted">{{ __('Create an OAuth client of type "Web application" in the Google Cloud console and enter its client ID and secret.') }}</div>
                            @endif

                            <div class="col-md-6">
                                <label for="sso-client-id" class="form-label">{{ __('Client ID') }}</label>
                                <input id="sso-client-id" type="text" wire:model="client_id" maxlength="255" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('client_id')])>
                                @error('client_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="sso-client-secret" class="form-label">{{ __('Client secret') }}</label>
                                <input id="sso-client-secret" type="password" wire:model="client_secret" maxlength="1000" autocomplete="new-password"
                                       placeholder="{{ $hasSecret ? __('Saved — leave blank to keep') : '' }}"
                                       @class(['form-control', 'is-invalid' => $errors->has('client_secret')])>
                                @error('client_secret') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="sso-scopes" class="form-label">{{ __('Scopes') }}</label>
                                <input id="sso-scopes" type="text" wire:model="scopes" maxlength="255" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('scopes')])>
                                @error('scopes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="sso-domains" class="form-label">{{ __('Allowed email domains') }}</label>
                                <input id="sso-domains" type="text" wire:model="allowed_domains" maxlength="2000" autocomplete="off" placeholder="example.com, example.co.uk"
                                       @class(['form-control', 'is-invalid' => $errors->has('allowed_domains')])>
                                <div class="form-text">{{ __('Leave empty to accept any domain of this provider.') }}</div>
                                @error('allowed_domains') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="small text-muted">{{ __('Redirect URI to register with the provider:') }}</div>
                                <code class="text-break">{{ $this->redirectUri }}</code>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sso-enabled" wire:model="enabled">
                                    <label class="form-check-label" for="sso-enabled">{{ __('Show this provider on the sign-in page') }}</label>
                                </div>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sso-jit" wire:model.live="jit_enabled">
                                    <label class="form-check-label" for="sso-jit">{{ __('Create an account for people from an allowed domain who do not have one yet') }}</label>
                                </div>
                                @if ($jit_enabled)
                                    <div class="mt-2 col-md-6">
                                        <label for="sso-jit-role" class="form-label">{{ __('Role for new accounts') }}</label>
                                        <select id="sso-jit-role" wire:model="jit_role" @class(['form-select', 'is-invalid' => $errors->has('jit_role')])>
                                            <option value="">{{ __('Choose a role') }}</option>
                                            @foreach ($this->roles as $role)
                                                <option value="{{ $role }}">{{ __($role) }}</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text">{{ __('New accounts use a license seat. Super Admin and artist accounts are never created automatically.') }}</div>
                                        @error('jit_role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            {{ __('Save provider') }}
                        </button>
                        <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="col-xl-4">
            <form wire:submit="savePolicy" class="card" novalidate>
                <div class="card-header">
                    <x-icon name="shield-check" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Policy') }}</h2>
                </div>
                <div class="card-body small">
                    <div class="form-check form-switch">
                        <input @class(['form-check-input', 'is-invalid' => $errors->has('require_sso')]) type="checkbox" role="switch" id="sso-require" wire:model.live="require_sso">
                        <label class="form-check-label" for="sso-require">{{ __('Require single sign-on for staff') }}</label>
                        @error('require_sso') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="sso-require-artists" wire:model="require_sso_artists" @disabled(! $require_sso)>
                        <label class="form-check-label" for="sso-require-artists">{{ __('Also for artist accounts') }}</label>
                    </div>
                    <p class="text-muted mt-3 mb-0">{{ __('Password sign-in is refused for these accounts, and "Keep me signed in" ends for them. Super Admins can always sign in with their password, so you cannot be locked out if a provider fails.') }}</p>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="savePolicy">
                        <span wire:loading wire:target="savePolicy" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        {{ __('Save policy') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

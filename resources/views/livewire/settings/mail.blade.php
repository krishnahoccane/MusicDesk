<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="server" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Outgoing mail') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('Used for password links, new-user invitations and notifications. Your hosting provider can give you SMTP details.') }}</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="mailer" class="form-label">{{ __('Delivery method') }}</label>
                                <select id="mailer" wire:model.live="mailer" class="form-select">
                                    <option value="smtp">{{ __('SMTP server') }}</option>
                                    <option value="sendmail">{{ __('Server mail (sendmail)') }}</option>
                                    <option value="log">{{ __('Log only (no email is sent)') }}</option>
                                </select>
                            </div>
                        </div>

                        @if ($mailer === 'smtp')
                            <div class="row g-3 mt-1">
                                <div class="col-md-6">
                                    <label for="host" class="form-label">{{ __('SMTP host') }}</label>
                                    <input id="host" type="text" wire:model="host" maxlength="255" placeholder="smtp.example.com" autocomplete="off"
                                           @class(['form-control', 'is-invalid' => $errors->has('host')])>
                                    @error('host') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-3">
                                    <label for="port" class="form-label">{{ __('Port') }}</label>
                                    <input id="port" type="number" wire:model="port" min="1" max="65535"
                                           @class(['form-control', 'is-invalid' => $errors->has('port')])>
                                    @error('port') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-3">
                                    <label for="scheme" class="form-label">{{ __('Encryption') }}</label>
                                    <select id="scheme" wire:model="scheme" class="form-select">
                                        <option value="">{{ __('STARTTLS / auto') }}</option>
                                        <option value="smtps">{{ __('SSL/TLS') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">{{ __('Username') }}</label>
                                    <input id="username" type="text" wire:model="username" maxlength="255" autocomplete="off" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label for="password" class="form-label">{{ __('Password') }}</label>
                                    <input id="password" type="password" wire:model="password" maxlength="255" autocomplete="new-password"
                                           placeholder="{{ $hasPassword ? __('Saved — leave blank to keep') : '' }}" class="form-control">
                                    @if ($hasPassword)
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="forget_password" wire:model="forget_password">
                                            <label class="form-check-label small" for="forget_password">{{ __('Remove the saved password') }}</label>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="form-text mt-2">{{ __('Port 587 usually uses STARTTLS; port 465 uses SSL/TLS.') }}</div>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <x-icon name="at-sign" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Sender') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="from_name" class="form-label">{{ __('Sender name') }}</label>
                                <input id="from_name" type="text" wire:model="from_name" maxlength="120"
                                       @class(['form-control', 'is-invalid' => $errors->has('from_name')])>
                                @error('from_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="from_address" class="form-label">{{ __('Sender address') }}</label>
                                <input id="from_address" type="email" wire:model="from_address" maxlength="255"
                                       @class(['form-control', 'is-invalid' => $errors->has('from_address')])>
                                @error('from_address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">
                        <x-icon name="send" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Test') }}</h2>
                    </div>
                    <div class="card-body small">
                        <p class="text-muted">{{ __('Sends a test message to :email using the saved settings. Save your changes first.', ['email' => auth()->user()->email]) }}</p>
                        <button type="button" class="btn btn-light" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                            <span wire:loading wire:target="sendTest" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            {{ __('Send test email') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ __('Save email settings') }}
            </button>
        </div>
    </form>
</div>

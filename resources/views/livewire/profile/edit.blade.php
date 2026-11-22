<div>
    <x-page-header :title="__('My profile')" :subtitle="__('Your personal details, appearance and password.')" />

    <div class="row g-3">
        <div class="col-lg-7">
            <form wire:submit="saveProfile" class="card mb-3" novalidate>
                <div class="card-header">
                    <x-icon name="user-round" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Profile') }}</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">{{ __('Full name') }}</label>
                            <input id="name" type="text" wire:model="name" maxlength="120" autocomplete="name"
                                   @class(['form-control', 'is-invalid' => $errors->has('name')]) required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">{{ __('Email address') }}</label>
                            <input id="email" type="email" wire:model.live.blur="email" maxlength="255" autocomplete="email"
                                   @class(['form-control', 'is-invalid' => $errors->has('email')]) required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        @if (Str::lower(trim($email)) !== $user->email)
                            <div class="col-md-6">
                                <label for="email_password" class="form-label">{{ __('Current password') }}</label>
                                <input id="email_password" type="password" wire:model="email_password" maxlength="255" autocomplete="current-password"
                                       @class(['form-control', 'is-invalid' => $errors->has('email_password')])>
                                <div class="form-text">{{ __('Required to change your email address.') }}</div>
                                @error('email_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        @endif
                        <div class="col-md-6">
                            <label for="theme" class="form-label">{{ __('Theme') }}</label>
                            <select id="theme" wire:model="theme" class="form-select">
                                @foreach ($themes as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body border-top d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveProfile">{{ __('Save profile') }}</button>
                </div>
            </form>

            <form wire:submit="savePassword" class="card" novalidate>
                <div class="card-header">
                    <x-icon name="lock-keyhole" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Change password') }}</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="current_password" class="form-label">{{ __('Current password') }}</label>
                            <input id="current_password" type="password" wire:model="current_password" maxlength="255" autocomplete="current-password" style="max-width: 360px"
                                   @class(['form-control', 'is-invalid' => $errors->has('current_password')])>
                            @error('current_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">{{ __('New password') }}</label>
                            <input id="password" type="password" wire:model="password" maxlength="255" autocomplete="new-password"
                                   @class(['form-control', 'is-invalid' => $errors->has('password')])>
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="password_confirmation" class="form-label">{{ __('Confirm new password') }}</label>
                            <input id="password_confirmation" type="password" wire:model="password_confirmation" maxlength="255" autocomplete="new-password" class="form-control">
                        </div>
                    </div>
                    <div class="form-text">{{ __('At least 10 characters with upper- and lower-case letters and a number. Changing it signs you out on other devices.') }}</div>
                </div>
                <div class="card-body border-top d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="savePassword">{{ __('Change password') }}</button>
                </div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <x-icon name="shield-check" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Account') }}</h2>
                </div>
                <div class="card-body py-2">
                    <dl class="ld-dl">
                        <dt>{{ __('Role') }}</dt><dd>{{ $roles->join(', ') ?: '—' }}</dd>
                        <dt>{{ __('Member since') }}</dt><dd>{{ App\Support\Format::date($user->created_at) }}</dd>
                        <dt>{{ __('Last sign-in') }}</dt><dd>{{ App\Support\Format::datetime($user->last_login_at, __('Never')) }}</dd>
                        <dt>{{ __('From IP') }}</dt><dd>{{ $user->last_login_ip ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
    @script
<script>
    $wire.on('profile-theme-changed', ({ theme }) => {
        document.querySelector(`[data-theme-option="${theme}"]`)?.click();
    });
</script>
@endscript
</div>

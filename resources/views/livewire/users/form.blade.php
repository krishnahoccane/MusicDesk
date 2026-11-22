<div>
    <x-page-header :title="$editing ? __('Edit user') : __('Add user')"
                   :subtitle="$editing ? $user->email : __('Create an account and choose what this person can access.')" />

    @if (! $editing && ! $seatsAvailable)
        <x-alert variant="warning" class="mb-4" :title="__('User allowance reached')">
            {{ __('Your license allows :limit active users. You can still add this person as a deactivated account.', ['limit' => $seatLimit]) }}
        </x-alert>
    @endif

    @error('form')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    <div class="row g-3">
        <div class="col-lg-8">
            <form wire:submit="save" class="card" novalidate>
                <div class="card-header">
                    <x-icon name="user-round" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Account') }}</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">{{ __('Full name') }}</label>
                            <input id="name" type="text" wire:model="name" maxlength="120" autocomplete="off"
                                   @class(['form-control', 'is-invalid' => $errors->has('name')]) required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">{{ __('Email address') }}</label>
                            <input id="email" type="email" wire:model="email" maxlength="255" autocomplete="off"
                                   @class(['form-control', 'is-invalid' => $errors->has('email')]) required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="role" class="form-label">{{ __('Role') }}</label>
                            <select id="role" wire:model.live="role" @class(['form-select', 'is-invalid' => $errors->has('role')]) @disabled($isSelf) required>
                                <option value="">{{ __('Choose a role…') }}</option>
                                @foreach ($roles as $r)
                                    <option value="{{ $r->name }}">{{ $r->name }}</option>
                                @endforeach
                            </select>
                            @if ($isSelf)
                                <div class="form-text">{{ __('You cannot change your own role.') }}</div>
                            @endif
                            @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        @if ($role === $artistRole)
                            <div class="col-12">
                                <label for="artist_ids" class="form-label">{{ __('Artist profiles') }}</label>
                                <select id="artist_ids" multiple size="6" wire:model="artist_ids" @class(['form-select', 'is-invalid' => $errors->has('artist_ids') || $errors->has('artist_ids.*')])>
                                    @foreach ($artistOptions as $id => $artistName)
                                        <option value="{{ $id }}">{{ $artistName }}</option>
                                    @endforeach
                                </select>
                                @error('artist_ids') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @error('artist_ids.*') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('An artist account only sees and edits these artists, their releases, tracks, contracts and earnings. Hold Ctrl (Cmd on Mac) to choose several.') }}</div>
                            </div>
                        @endif
                        @unless ($editing)
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" wire:model.live="is_active">
                                    <label class="form-check-label" for="is_active">{{ __('Account is active') }}</label>
                                </div>
                            </div>
                        @endunless
                    </div>
                </div>

                <div class="card-header border-top">
                    <x-icon name="lock-keyhole" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Password') }}</h2>
                </div>
                <div class="card-body">
                    @unless ($editing)
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="send_link" wire:model.live="send_link" @disabled(! $is_active)>
                            <label class="form-check-label" for="send_link">{{ __('Email them a link to set their own password') }}</label>
                            <div class="form-text">{{ __('Recommended. The link is valid for :minutes minutes; you can send a new one from the user list.', ['minutes' => config('auth.passwords.users.expire')]) }}</div>
                        </div>
                    @endunless

                    @if ($editing || ! $send_link || ! $is_active)
                        @if ($editing)
                            <p class="small text-muted">{{ __('Leave blank to keep the current password. Setting a new one signs this user out everywhere.') }}</p>
                        @endif
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="password" class="form-label">{{ $editing ? __('New password') : __('Password') }}</label>
                                <input id="password" type="password" wire:model="password" maxlength="255" autocomplete="new-password"
                                       @class(['form-control', 'is-invalid' => $errors->has('password')])>
                                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_confirmation" class="form-label">{{ __('Confirm password') }}</label>
                                <input id="password_confirmation" type="password" wire:model="password_confirmation" maxlength="255" autocomplete="new-password" class="form-control">
                            </div>
                        </div>
                        <div class="form-text">{{ __('At least 10 characters with upper- and lower-case letters and a number.') }}</div>
                    @endif
                </div>

                <div class="card-body border-top d-flex justify-content-end gap-2">
                    <a href="{{ route('users.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        {{ $editing ? __('Save changes') : __('Add user') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            @if ($editing)
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="info" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Details') }}</h2>
                    </div>
                    <div class="card-body py-2">
                        <dl class="ld-dl">
                            <dt>{{ __('Status') }}</dt><dd>{{ $user->is_active ? __('Active') : __('Deactivated') }}</dd>
                            <dt>{{ __('Created') }}</dt><dd>{{ App\Support\Format::datetime($user->created_at) }}</dd>
                            <dt>{{ __('Last sign-in') }}</dt><dd>{{ App\Support\Format::datetime($user->last_login_at, __('Never')) }}</dd>
                        </dl>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <x-icon name="history" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Recent activity') }}</h2>
                    </div>
                    @if ($activity->isEmpty())
                        <div class="card-body small text-muted">{{ __('No activity recorded yet.') }}</div>
                    @else
                        <ul class="list-unstyled mb-0 px-4 py-2 small">
                            @foreach ($activity as $entry)
                                <li class="py-1">
                                    <div>{{ $entry->description }}</div>
                                    <div class="text-muted">{{ App\Support\Format::datetime($entry->created_at) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @else
                <div class="card">
                    <div class="card-body small text-muted">
                        <p class="fw-semibold text-body mb-2">{{ __('About roles') }}</p>
                        <p class="mb-2">{{ __('A role decides which areas a person can see and change.') }}</p>
                        @can('roles.view')
                            <a href="{{ route('roles.index') }}">{{ __('Review roles and permissions') }} &rarr;</a>
                        @endcan
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<x-layouts.auth :title="__('Create an account')">
    <h1 class="ld-login__title">{{ __('Create an account') }}</h1>
    <p class="text-muted mb-4">{{ __('Join :name. An administrator reviews every new account before it can sign in.', ['name' => config('app.name')]) }}</p>

    <form method="POST" action="{{ route('register.store') }}" novalidate x-data="{ type: @js($type) }">
        @csrf

        @if (count($types) > 1)
            <div class="mb-3" role="radiogroup" aria-label="{{ __('Account type') }}">
                <div class="btn-group w-100">
                    <input type="radio" class="btn-check" name="type" id="type-artist" value="artist" x-model="type" autocomplete="off">
                    <label class="btn btn-outline-primary" for="type-artist"><x-icon name="mic-vocal" size="16" /> {{ __('I\'m an artist') }}</label>
                    <input type="radio" class="btn-check" name="type" id="type-label" value="label" x-model="type" autocomplete="off">
                    <label class="btn btn-outline-primary" for="type-label"><x-icon name="tags" size="16" /> {{ __('I\'m a label') }}</label>
                </div>
                @error('type') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
        @else
            <input type="hidden" name="type" value="{{ $types[0] }}">
            @error('type') <div class="alert alert-warning small">{{ $message }}</div> @enderror
        @endif

        <div class="mb-3" x-show="type === 'artist'" @if ($type !== 'artist') x-cloak @endif>
            <label for="artist_name" class="form-label">{{ __('Artist name') }}</label>
            <input id="artist_name" name="artist_name" value="{{ old('artist_name') }}" maxlength="150" @class(['form-control', 'is-invalid' => $errors->has('artist_name')]) :required="type === 'artist'">
            @error('artist_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div class="form-text">{{ __('The name you release music under.') }}</div>
        </div>

        <div x-show="type === 'label'" @if ($type !== 'label') x-cloak @endif>
            <div class="mb-3">
                <label for="label_name" class="form-label">{{ __('Label name') }}</label>
                <input id="label_name" name="label_name" value="{{ old('label_name') }}" maxlength="150" @class(['form-control', 'is-invalid' => $errors->has('label_name')]) :required="type === 'label'">
                @error('label_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="mb-3">
                <label for="website" class="form-label">{{ __('Label website') }} <span class="text-subtle small">({{ __('optional') }})</span></label>
                <input id="website" type="url" name="website" value="{{ old('website') }}" maxlength="255" placeholder="https://" @class(['form-control', 'is-invalid' => $errors->has('website')])>
                @error('website') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-8">
                <label for="name" class="form-label">{{ __('Your full name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required autocomplete="name" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-4">
                <label for="country" class="form-label">{{ __('Country') }}</label>
                <input id="country" name="country" value="{{ old('country') }}" maxlength="2" placeholder="GB" autocomplete="country" @class(['form-control text-uppercase', 'is-invalid' => $errors->has('country')])>
                @error('country') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email address') }}</label>
            <div class="input-icon">
                <x-icon name="mail" size="16" />
                <input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email" @class(['form-control', 'is-invalid' => $errors->has('email')])>
            </div>
            @error('email') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>

        <div class="row g-2 mb-3">
            <div class="col-sm-6">
                <label for="password" class="form-label">{{ __('Password') }}</label>
                <input id="password" type="password" name="password" maxlength="255" required autocomplete="new-password" @class(['form-control', 'is-invalid' => $errors->has('password')])>
            </div>
            <div class="col-sm-6">
                <label for="password_confirmation" class="form-label">{{ __('Repeat password') }}</label>
                <input id="password_confirmation" type="password" name="password_confirmation" maxlength="255" required autocomplete="new-password" class="form-control">
            </div>
            @error('password') <div class="col-12"><div class="invalid-feedback d-block">{{ $message }}</div></div> @enderror
        </div>

        {{-- Left empty by people; filled in by bots. --}}
        <div aria-hidden="true" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden">
            <label for="company_url">{{ __('Leave this field empty') }}</label>
            <input id="company_url" name="company_url" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="confirm" @checked(old('confirm'))>
            <label class="form-check-label small" for="confirm">
                <span x-show="type === 'artist'">{{ __('The details are correct and I am this artist or represent them.') }}</span>
                <span x-show="type === 'label'" x-cloak>{{ __('The details are correct and I represent this label.') }}</span>
            </label>
            @error('confirm') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">
            {{ __('Create account') }}
            <x-icon name="arrow-right" size="16" />
        </button>
    </form>

    <p class="text-center small text-muted mt-4 mb-0">
        {{ __('Already have an account?') }} <a href="{{ route('login') }}" class="fw-semibold">{{ __('Sign in') }}</a>
    </p>
</x-layouts.auth>

@php($loginTexts = $texts ?? App\Support\Appearance::loginTexts())
<x-layouts.auth :title="__('Sign in')" :design="$design ?? null" :texts="$loginTexts" :preview="$preview ?? false">
    <h1 class="ld-login__title">{{ $loginTexts['welcome'] }}</h1>
    <p class="text-muted mb-4">{{ __('Sign in to :name to continue.', ['name' => config('app.name')]) }}</p>

    @if (session('status') && ! ($preview ?? false))
        <x-alert variant="success" class="mb-3">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ ($preview ?? false) ? '#' : route('login.store') }}" novalidate>
        @csrf
        <fieldset @disabled($preview ?? false) class="border-0 p-0 m-0">

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email address') }}</label>
            <div class="input-icon">
                <x-icon name="mail" size="16" />
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       @class(['form-control', 'is-invalid' => $errors->has('email')])
                       required autofocus autocomplete="username" maxlength="255"
                       aria-describedby="email-error">
            </div>
            @error('email')
                <div id="email-error" class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('Password') }}</label>
            <div class="input-icon">
                <x-icon name="lock" size="16" />
                <input id="password" type="password" name="password"
                       @class(['form-control', 'is-invalid' => $errors->has('password')])
                       required autocomplete="current-password" maxlength="255">
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember" @checked(old('remember'))>
                <label class="form-check-label" for="remember">{{ __('Keep me signed in') }}</label>
            </div>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="small">{{ __('Forgot password?') }}</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">
            {{ __('Sign in') }}
            <x-icon name="arrow-right" size="16" />
        </button>
        </fieldset>
    </form>
    @if (app(\App\Services\Auth\RegistrationService::class)->enabled())
        <p class="text-center small text-muted mt-4 mb-0">
            {{ __('New here?') }}
            <a href="{{ ($preview ?? false) ? '#' : route('register') }}" class="fw-semibold">{{ __('Create an account') }}</a>
        </p>
    @endif
    @foreach (app(\App\Plugins\PluginRegistry::class)->loginButtons as $button)
        @include($button['view'], ['preview' => $preview ?? false])
    @endforeach
</x-layouts.auth>

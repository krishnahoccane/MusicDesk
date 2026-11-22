<x-layouts.auth :title="__('Forgot password')">
    <h1 class="ld-login__title">{{ __('Forgot your password?') }}</h1>
    <p class="text-muted mb-4">{{ __('Enter the email address of your account and we will send you a link to choose a new password.') }}</p>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <div class="mb-4">
            <label for="email" class="form-label">{{ __('Email address') }}</label>
            <div class="input-icon">
                <x-icon name="mail" size="16" />
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       @class(['form-control', 'is-invalid' => $errors->has('email')])
                       required autofocus autocomplete="username" maxlength="255">
            </div>
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">
            <x-icon name="send" size="16" />
            {{ __('Email reset link') }}
        </button>
    </form>

    <p class="text-center small mt-4 mb-0">
        <a href="{{ route('login') }}"><x-icon name="arrow-left" size="14" /> {{ __('Back to sign in') }}</a>
    </p>
</x-layouts.auth>

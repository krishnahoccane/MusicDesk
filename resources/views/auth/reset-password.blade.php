<x-layouts.auth :title="__('Choose a new password')">
    <h1 class="ld-login__title">{{ __('Choose a new password') }}</h1>
    <p class="text-muted mb-4">{{ __('Use at least 10 characters with upper- and lower-case letters and a number.') }}</p>

    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email address') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}"
                   @class(['form-control', 'is-invalid' => $errors->has('email')])
                   required autocomplete="username" maxlength="255">
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('New password') }}</label>
            <input id="password" type="password" name="password"
                   @class(['form-control', 'is-invalid' => $errors->has('password')])
                   required autofocus autocomplete="new-password" maxlength="255">
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">{{ __('Confirm new password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" class="form-control"
                   required autocomplete="new-password" maxlength="255">
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">{{ __('Reset password') }}</button>
    </form>
</x-layouts.auth>

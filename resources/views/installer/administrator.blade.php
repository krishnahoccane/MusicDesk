<x-layouts.installer :title="__('Administrator')" :step="$step">
    <div class="eyebrow mb-2">{{ __('Step :n', ['n' => 5]) }}</div>
    <h1>{{ __('Create the super admin account') }}</h1>
    <p class="ld-installer__lead">{{ __('This account receives the Super Admin role with full access. You can invite your team after installation.') }}</p>

    <form method="POST" action="{{ route('installer.administrator.store') }}" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">{{ __('Full name') }}</label>
                <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $values['name']) }}" required maxlength="120" autocomplete="name">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">{{ __('Email') }}</label>
                <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $values['email']) }}" required maxlength="255" autocomplete="email">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password">{{ __('Password') }}</label>
                <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" required maxlength="255" autocomplete="new-password">
                <div class="form-text">{{ __('At least 10 characters with upper and lower case letters and a number.') }}</div>
                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password_confirmation">{{ __('Confirm password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" required maxlength="255" autocomplete="new-password">
            </div>
        </div>

        <div class="ld-installer__actions">
            <a href="{{ route('installer.mail') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Continue') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

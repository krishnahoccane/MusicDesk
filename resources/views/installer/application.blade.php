<x-layouts.installer :title="__('Application')" :step="$step">
    <h1>{{ __('Application settings') }}</h1>
    <p class="ld-installer__lead">{{ __('These can be changed later in Settings, except the application URL, which your license is bound to.') }}</p>

    <form method="POST" action="{{ route('installer.application.store') }}" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">{{ __('Application / label name') }}</label>
                <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $values['name']) }}" required maxlength="80">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="url">{{ __('Application URL') }}</label>
                <input id="url" name="url" type="url" class="form-control @error('url') is-invalid @enderror" value="{{ old('url', $values['url']) }}" required maxlength="255">
                <div class="form-text">{{ __('The address users will open, including https://.') }}</div>
                @error('url') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="timezone">{{ __('Timezone') }}</label>
                <select id="timezone" name="timezone" class="form-select @error('timezone') is-invalid @enderror">
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', $values['timezone']) === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
                @error('timezone') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="currency">{{ __('Default currency') }}</label>
                <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
                    @foreach ($currencies as $code)
                        <option value="{{ $code }}" @selected(old('currency', $values['currency']) === $code)>{{ $code }}</option>
                    @endforeach
                </select>
                @error('currency') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="locale">{{ __('Language') }}</label>
                <select id="locale" name="locale" class="form-select @error('locale') is-invalid @enderror">
                    @foreach ($locales as $code => $label)
                        <option value="{{ $code }}" @selected(old('locale', $values['locale']) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if (! str_starts_with(old('url', $values['url']), 'https://'))
            <x-alert variant="warning" class="mt-4">{{ __('HTTPS is strongly recommended. Enable a free SSL certificate in your hosting panel and use an https:// URL.') }}</x-alert>
        @endif

        <div class="ld-installer__actions">
            <a href="{{ route('installer.database') }}" class="btn btn-light"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Continue') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

<x-layouts.installer :title="__('Email')" :step="$step">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="eyebrow">{{ __('Step :n', ['n' => 4]) }}</span>
        <x-badge>{{ __('can be skipped') }}</x-badge>
    </div>
    <h1>{{ __('Outgoing email (SMTP)') }}</h1>
    <p class="ld-installer__lead">{{ __('Used for password resets, team invitations and notifications. Use the SMTP details from your hosting control panel or email provider. You can also do this later in Settings → Email.') }}</p>

    @error('mail')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    <form method="POST" action="{{ route('installer.mail.store') }}" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="host">{{ __('SMTP server') }}</label>
                <input id="host" name="host" class="form-control font-mono @error('host') is-invalid @enderror" value="{{ old('host', $values['host']) }}" placeholder="mail.yourlabel.com" maxlength="255" autocomplete="off">
                @error('host') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-2">
                <label class="form-label" for="port">{{ __('Port') }}</label>
                <input id="port" name="port" type="number" min="1" max="65535" class="form-control font-mono @error('port') is-invalid @enderror" value="{{ old('port', $values['port']) }}">
                @error('port') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="encryption">{{ __('Encryption') }}</label>
                <select id="encryption" name="encryption" class="form-select">
                    @foreach ($encryptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('encryption', $values['encryption']) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="username">{{ __('Username') }}</label>
                <input id="username" name="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username', $values['username']) }}" maxlength="255" autocomplete="off">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password">{{ __('Password') }}</label>
                <input id="password" name="password" type="password" class="form-control" maxlength="255" autocomplete="new-password"
                       placeholder="{{ $hasPassword ? __('Leave blank to keep the saved password') : '' }}">
                @if ($hasPassword) <input type="hidden" name="keep_password" value="1"> @endif
            </div>
            <div class="col-md-6">
                <label class="form-label" for="from_address">{{ __('Send from address') }}</label>
                <input id="from_address" name="from_address" type="email" class="form-control @error('from_address') is-invalid @enderror" value="{{ old('from_address', $values['from_address']) }}" placeholder="no-reply@yourlabel.com" maxlength="255">
                @error('from_address') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="from_name">{{ __('Sender name') }}</label>
                <input id="from_name" name="from_name" class="form-control @error('from_name') is-invalid @enderror" value="{{ old('from_name', $values['from_name']) }}" placeholder="{{ __('Your label') }}" maxlength="120">
                @error('from_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="ld-notice mt-4">
            <label class="form-label" for="test_to">{{ __('Send a test email to') }} <span class="text-subtle">({{ __('defaults to the sender address') }})</span></label>
            <div class="d-flex flex-wrap gap-2">
                <input id="test_to" name="test_to" type="email" class="form-control @error('test_to') is-invalid @enderror" style="max-width: 320px" value="{{ old('test_to') }}" maxlength="255">
                <button type="submit" name="action" value="test" class="btn btn-light"><x-icon name="send" size="16" /> {{ __('Send test email') }}</button>
            </div>
            @error('test_to') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>

        <div class="ld-installer__actions">
            <a href="{{ route('installer.redis') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <div class="d-flex gap-2">
                <button type="submit" form="skip-mail" class="btn btn-ghost">{{ __('Skip for now') }}</button>
                <button type="submit" name="action" value="save" class="btn btn-primary">{{ __('Save & continue') }} <x-icon name="arrow-right" size="16" /></button>
            </div>
        </div>
    </form>
    <form id="skip-mail" method="POST" action="{{ route('installer.mail.skip') }}">@csrf</form>
</x-layouts.installer>

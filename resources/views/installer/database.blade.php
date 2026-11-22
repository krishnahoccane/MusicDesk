<x-layouts.installer :title="__('Database')" :step="$step">
    <div class="eyebrow mb-2">{{ __('Step :n', ['n' => 2]) }}</div>
    <h1>{{ __('Database configuration') }}</h1>
    <p class="ld-installer__lead">{{ __('Create an empty MySQL or MariaDB database and user in your hosting control panel, then enter the details below. The connection is tested before continuing.') }}</p>

    @error('database')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    <form method="POST" action="{{ route('installer.database.store') }}" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label" for="host">{{ __('Database hostname') }}</label>
                <input id="host" name="host" class="form-control @error('host') is-invalid @enderror" value="{{ old('host', $values['host']) }}" required maxlength="255" autocomplete="off">
                <div class="form-text">{{ __('Usually "localhost" or 127.0.0.1 on shared hosting.') }}</div>
                @error('host') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="port">{{ __('Port') }}</label>
                <input id="port" name="port" type="number" min="1" max="65535" class="form-control @error('port') is-invalid @enderror" value="{{ old('port', $values['port']) }}" required>
                @error('port') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="database">{{ __('Database name') }}</label>
                <input id="database" name="database" class="form-control @error('database') is-invalid @enderror" value="{{ old('database', $values['database']) }}" required maxlength="64" autocomplete="off">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="prefix">{{ __('Table prefix') }} <span class="text-subtle">({{ __('optional') }})</span></label>
                <input id="prefix" name="prefix" class="form-control @error('prefix') is-invalid @enderror" value="{{ old('prefix', $values['prefix']) }}" maxlength="20" placeholder="ld_" autocomplete="off">
                @error('prefix') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="username">{{ __('Database username') }}</label>
                <input id="username" name="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username', $values['username']) }}" required maxlength="128" autocomplete="off">
                @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password">{{ __('Database password') }}</label>
                <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" maxlength="255" autocomplete="new-password"
                       placeholder="{{ $hasPassword ? __('Leave blank to keep the saved password') : '' }}">
                @if ($hasPassword)
                    <input type="hidden" name="keep_password" value="1">
                @endif
                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <x-alert variant="info" class="mt-4">
            {{ __('LabelDesk only installs into an empty database (or an empty table prefix). It never overwrites an existing database.') }}
        </x-alert>

        <div class="ld-installer__actions">
            <a href="{{ route('installer.requirements') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" class="btn btn-primary"><x-icon name="plug-zap" size="16" /> {{ __('Test connection & continue') }}</button>
        </div>
    </form>
</x-layouts.installer>

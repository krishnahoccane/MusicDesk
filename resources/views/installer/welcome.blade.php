<x-layouts.installer :title="__('Welcome')" :step="$step">
    <h1>{{ __('Welcome to LabelDesk') }}</h1>
    <p class="ld-installer__lead">{{ __('This wizard installs LabelDesk on your server in a few minutes. You will need your database details and the license key from your MusicDesk.tech account.') }}</p>

    @if ($resuming)
        <x-alert variant="warning" :title="__('A previous installation did not finish')" class="mb-4">
            {{ __('Continue the wizard with the same database to resume. Existing data is never removed.') }}
        </x-alert>
    @endif

    <div class="row g-3 mb-4">
        @foreach ([
            ['icon' => 'server', 'title' => __('Server check'), 'text' => __('PHP version, extensions and folder permissions.')],
            ['icon' => 'database', 'title' => __('Database'), 'text' => __('An empty MySQL 8 or MariaDB 10.6+ database.')],
            ['icon' => 'key-round', 'title' => __('License'), 'text' => __('Your purchase license key from MusicDesk.tech.')],
        ] as $item)
            <div class="col-md-4">
                <div class="d-flex gap-3 h-100 p-3 rounded-3" style="background: var(--ld-surface-2)">
                    <span class="ld-stat__icon"><x-icon :name="$item['icon']" size="20" /></span>
                    <div>
                        <div class="fw-semibold" style="color: var(--ld-heading)">{{ $item['title'] }}</div>
                        <div class="small text-muted">{{ $item['text'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <dl class="ld-dl mb-4">
        <dt>{{ __('Product') }}</dt><dd>{{ config('labeldesk.product.name') }}</dd>
        <dt>{{ __('Version') }}</dt><dd>{{ config('labeldesk.product.version') }}</dd>
        <dt>{{ __('Publisher') }}</dt><dd>MusicDesk &middot; musicdesk.tech</dd>
    </dl>

    <h2 class="h6 fw-semibold">{{ __('Installation terms') }}</h2>
    <div class="ld-terms mb-3" tabindex="0">
        <p>{{ __('LabelDesk is commercial software licensed, not sold. Each license key may be activated on the number of installations included in your purchased plan.') }}</p>
        <p>{{ __('During installation and periodically afterwards, LabelDesk contacts MusicDesk.tech over HTTPS to verify your license. Only the license key, installation domain, application URL, product version and an anonymous installation fingerprint are sent. Your label data never leaves your server.') }}</p>
        <p>{{ __('You are responsible for backing up your server and database. MusicDesk is not liable for data loss caused by hosting failures.') }}</p>
        <p class="mb-0">{{ __('The full license agreement is available at musicdesk.tech.') }}</p>
    </div>

    <form method="POST" action="{{ route('installer.welcome.store') }}">
        @csrf
        <div class="form-check">
            <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" name="terms" value="1" id="terms">
            <label class="form-check-label" for="terms">{{ __('I have read and accept the installation terms.') }}</label>
            @error('terms') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="ld-installer__actions">
            <span class="small text-muted">{{ __('Step 1 of 8') }}</span>
            <button type="submit" class="btn btn-primary">{{ __('Start installation') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

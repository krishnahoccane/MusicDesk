<x-layouts.installer :title="__('Complete')" :step="$step">
    <div class="text-center mb-4">
        <span class="ld-stat__icon ld-stat__icon--success mb-3" style="width:64px;height:64px;border-radius:50%"><x-icon name="party-popper" size="30" /></span>
        <h1>{{ __('LabelDesk is installed') }}</h1>
        <p class="ld-installer__lead mb-0">{{ __('The installer is now locked and can no longer be accessed.') }}</p>
    </div>

    <dl class="ld-dl mb-4">
        <dt>{{ __('Application') }}</dt><dd>{{ $summary['app_name'] ?? '' }}</dd>
        <dt>{{ __('Login URL') }}</dt><dd class="text-break"><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></dd>
        <dt>{{ __('Administrator email') }}</dt><dd>{{ $summary['admin_email'] ?? '' }}</dd>
        <dt>{{ __('License plan') }}</dt><dd class="text-capitalize">{{ $summary['plan'] ?? '—' }}</dd>
    </dl>

    <h2 class="h6 fw-semibold">{{ __('Security recommendations') }}</h2>
    <ul class="ld-check-list mb-4">
        @foreach ([
            __('Point your domain\'s document root to the LabelDesk "public" folder so application files are not web-accessible.'),
            __('Use HTTPS for the application URL.'),
            __('Schedule regular database and file backups in your hosting panel and in LabelDesk → Backups.'),
            __('Keep your administrator password private and enable accounts only for people who need them.'),
        ] as $tip)
            <li class="is-pass"><x-icon name="shield-check" size="17" /> <span class="small">{{ $tip }}</span></li>
        @endforeach
    </ul>

    <div class="text-center">
        <a href="{{ $loginUrl }}" class="btn btn-primary px-4">{{ __('Go to sign in') }} <x-icon name="arrow-right" size="16" /></a>
    </div>
</x-layouts.installer>

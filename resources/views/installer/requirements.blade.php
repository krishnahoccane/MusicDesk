<x-layouts.installer :title="__('Server requirements')" :step="$step">
    <h1>{{ __('Server requirements') }}</h1>
    <p class="ld-installer__lead">{{ __('LabelDesk checked your hosting environment. Items marked as failed must be fixed before continuing; warnings are recommendations.') }}</p>

    @unless ($passes)
        <x-alert variant="danger" class="mb-4" :title="__('Some requirements are not met')">
            {{ __('Ask your hosting provider to enable the missing items (most can be enabled in cPanel → Select PHP Version), then reload this page.') }}
        </x-alert>
    @endunless

    <div class="row g-4">
        @foreach ($groups as $group => $checks)
            <div class="col-md-6">
                <h2 class="h6 fw-semibold mb-2">{{ __($group) }}</h2>
                <ul class="ld-check-list">
                    @foreach ($checks as $check)
                        <li class="is-{{ $check['status'] }}">
                            <x-icon :name="match ($check['status']) { 'pass' => 'circle-check-big', 'warn' => 'triangle-alert', default => 'circle-x' }" size="17" />
                            <span class="font-monospace small">{{ $check['label'] }}</span>
                            <span class="ld-check-list__meta">
                                {{ $check['current'] }}
                                @if ($check['status'] !== 'pass')
                                    <br><span class="text-subtle">{{ $check['required'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('installer.requirements.store') }}">
        @csrf
        <div class="ld-installer__actions">
            <a href="{{ route('installer.requirements') }}" class="btn btn-light"><x-icon name="refresh-cw" size="16" /> {{ __('Check again') }}</a>
            <button type="submit" class="btn btn-primary" @disabled(! $passes)>{{ __('Continue') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

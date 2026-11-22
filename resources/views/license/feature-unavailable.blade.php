<x-layouts.app :title="__('Not included in your plan')">
    <div class="card">
        <x-empty-state icon="lock" :title="__(':feature is not included in your plan', ['feature' => $feature])"
                       :description="__('Upgrade your LabelDesk license on MusicDesk.tech to use this feature. Your existing data is not affected.')">
            <a href="{{ config('licensing.customer_portal_url') }}" class="btn btn-primary" target="_blank" rel="noopener">{{ __('View upgrade options') }}</a>
            @can('license.view')
                <a href="{{ route('license.show') }}" class="btn btn-light ms-2">{{ __('License information') }}</a>
            @endcan
        </x-empty-state>
    </div>
</x-layouts.app>

@php($tabs = [
    ['route' => 'settings.index', 'label' => __('General'), 'icon' => 'sliders-horizontal', 'can' => 'settings.manage'],
    ['route' => 'settings.appearance', 'label' => __('Appearance'), 'icon' => 'palette', 'can' => 'settings.manage'],
    ['route' => 'settings.catalog', 'label' => __('Catalogue'), 'icon' => 'disc-3', 'can' => 'settings.manage'],
    ['route' => 'settings.mail', 'label' => __('Email'), 'icon' => 'mail', 'can' => 'settings.manage'],
    ['route' => 'license.show', 'label' => __('License'), 'icon' => 'key-round', 'can' => 'license.view'],
])
<ul class="nav nav-underline mb-4 ld-settings-tabs">
    @foreach ($tabs as $tab)
        @can($tab['can'])
            <li class="nav-item">
                <a href="{{ route($tab['route']) }}" @class(['nav-link d-flex align-items-center gap-2', 'active' => request()->routeIs($tab['route'])])
                   @if (request()->routeIs($tab['route'])) aria-current="page" @endif>
                    <x-icon :name="$tab['icon']" size="16" /> {{ $tab['label'] }}
                </a>
            </li>
        @endcan
    @endforeach
</ul>

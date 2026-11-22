<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <form wire:submit="save" novalidate
          x-data="{ theme: @js($theme), sidebar: @js($sidebar) }"
          x-effect="document.documentElement.dataset.ldTheme = theme; document.documentElement.dataset.ldSidebar = sidebar">
        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="palette" size="18" />
                <h2 class="card-title">{{ __('Colour theme') }}</h2>
                <span class="small text-muted ms-auto d-none d-md-inline">{{ __('Click a theme to preview it') }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($themes as $key => [$label, $accent, $accent2, $darkAccent, $darkAccent2, $lightBg, $darkBg])
                        <div class="col-6 col-md-4 col-xl-3" wire:key="theme-{{ $key }}">
                            <button type="button" @class(['ld-theme-card', 'is-selected' => $theme === $key])
                                    wire:click="$set('theme', '{{ $key }}')" x-on:click="theme = '{{ $key }}'"
                                    aria-pressed="{{ $theme === $key ? 'true' : 'false' }}">
                                <span class="ld-theme-card__check"><x-icon name="check" size="14" /></span>
                                <span class="ld-theme-preview" aria-hidden="true">
                                    <div style="background: {{ $lightBg }}">
                                        <i style="width: 70%; background: {{ $accent }}"></i>
                                        <i style="width: 45%; background: {{ $accent2 }}"></i>
                                        <i style="width: 85%; background: rgba(0,0,0,.08)"></i>
                                        <i style="width: 60%; background: rgba(0,0,0,.08)"></i>
                                    </div>
                                    <div style="background: {{ $darkBg }}">
                                        <i style="width: 70%; background: {{ $darkAccent }}"></i>
                                        <i style="width: 45%; background: {{ $darkAccent2 }}"></i>
                                        <i style="width: 85%; background: rgba(255,255,255,.12)"></i>
                                        <i style="width: 60%; background: rgba(255,255,255,.12)"></i>
                                    </div>
                                </span>
                                <span class="fw-semibold">{{ __($label) }}</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <x-icon name="sun-moon" size="18" />
                        <h2 class="card-title">{{ __('Light and dark mode') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('Choose which colour modes people can use.') }}</p>
                        <div class="ld-segmented mb-3" role="radiogroup" aria-label="{{ __('Allowed colour modes') }}">
                            @foreach (['both' => [__('Light & dark'), 'sun-moon'], 'light' => [__('Light only'), 'sun'], 'dark' => [__('Dark only'), 'moon']] as $value => [$label, $icon])
                                <input type="radio" id="modes-{{ $value }}" value="{{ $value }}" wire:model.live="modes">
                                <label for="modes-{{ $value }}"><x-icon :name="$icon" size="15" /> {{ $label }}</label>
                            @endforeach
                        </div>

                        @if ($modes === 'both')
                            <label class="form-label d-block" for="default_mode">{{ __('Default for people who have not chosen') }}</label>
                            <select id="default_mode" wire:model="default_mode" class="form-select" style="max-width: 260px">
                                <option value="system">{{ __('Follow the device setting') }}</option>
                                <option value="light">{{ __('Light') }}</option>
                                <option value="dark">{{ __('Dark') }}</option>
                            </select>
                            <div class="form-text">{{ __('Everyone can still switch with the sun/moon button in the top bar.') }}</div>
                        @else
                            <x-alert variant="info">{{ $modes === 'light' ? __('Everyone uses light mode and the mode switch is hidden.') : __('Everyone uses dark mode and the mode switch is hidden.') }}</x-alert>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <x-icon name="panel-left" size="18" />
                        <h2 class="card-title">{{ __('Sidebar style') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('How the navigation sidebar looks.') }}</p>
                        <div class="ld-segmented" role="radiogroup" aria-label="{{ __('Sidebar style') }}">
                            @foreach (['light' => __('Clean'), 'tinted' => __('Tinted'), 'dark' => __('Dark')] as $value => $label)
                                <input type="radio" id="sidebar-{{ $value }}" value="{{ $value }}" wire:model="sidebar" x-model="sidebar">
                                <label for="sidebar-{{ $value }}">{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ __('Save appearance') }}
            </button>
            <a href="{{ route('settings.appearance') }}" class="btn btn-light">{{ __('Discard preview') }}</a>
        </div>
    </form>
</div>

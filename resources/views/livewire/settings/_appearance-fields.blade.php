{{-- Settings → Appearance fields (Livewire). --}}
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
                            </div>
                            <div style="background: {{ $darkBg }}">
                                <i style="width: 70%; background: {{ $darkAccent }}"></i>
                                <i style="width: 45%; background: {{ $darkAccent2 }}"></i>
                                <i style="width: 85%; background: rgba(255,255,255,.12)"></i>
                            </div>
                        </span>
                        <span class="fw-semibold">{{ __($label) }}</span>
                    </button>
                </div>
            @endforeach
        </div>

        <div class="border-top mt-4 pt-3">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="custom_colors" wire:model.live="custom_colors">
                <label class="form-check-label" for="custom_colors">{{ __('Use my own brand colours instead') }}</label>
            </div>
            @if ($custom_colors)
                <div class="row g-3" style="max-width: 520px">
                    <div class="col-6">
                        <label class="form-label" for="brand_color">{{ __('Primary colour') }}</label>
                        <div class="d-flex gap-2">
                            <input type="color" class="form-control form-control-color" wire:model.live.debounce.250ms="brand_color" aria-label="{{ __('Primary colour') }}">
                            <input id="brand_color" class="form-control font-mono @error('brand_color') is-invalid @enderror" wire:model.live.debounce.400ms="brand_color" maxlength="7">
                        </div>
                        @error('brand_color') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="brand_color_2">{{ __('Accent colour') }}</label>
                        <div class="d-flex gap-2">
                            <input type="color" class="form-control form-control-color" wire:model.live.debounce.250ms="brand_color_2" aria-label="{{ __('Accent colour') }}">
                            <input id="brand_color_2" class="form-control font-mono @error('brand_color_2') is-invalid @enderror" wire:model.live.debounce.400ms="brand_color_2" maxlength="7">
                        </div>
                        @error('brand_color_2') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="form-text">{{ __('Lighter shades for dark mode are worked out automatically.') }}</div>
            @endif
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
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
                        <option value="dark">{{ __('Dark') }}</option>
                        <option value="light">{{ __('Light') }}</option>
                        <option value="system">{{ __('Follow the device setting') }}</option>
                    </select>
                @else
                    <x-alert variant="info">{{ $modes === 'light' ? __('Everyone uses light mode and the mode switch is hidden.') : __('Everyone uses dark mode and the mode switch is hidden.') }}</x-alert>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <x-icon name="type" size="18" />
                <h2 class="card-title">{{ __('Sidebar and font') }}</h2>
            </div>
            <div class="card-body">
                <label class="form-label d-block">{{ __('Sidebar style') }}</label>
                <div class="ld-segmented mb-3" role="radiogroup" aria-label="{{ __('Sidebar style') }}">
                    @foreach (['light' => __('Clean'), 'tinted' => __('Tinted'), 'dark' => __('Dark')] as $value => $label)
                        <input type="radio" id="sidebar-{{ $value }}" value="{{ $value }}" wire:model="sidebar" x-model="sidebar">
                        <label for="sidebar-{{ $value }}">{{ $label }}</label>
                    @endforeach
                </div>
                <label class="form-label d-block" for="font">{{ __('Font') }}</label>
                <select id="font" wire:model="font" class="form-select" style="max-width: 260px">
                    @foreach ($fonts as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <x-icon name="image" size="18" />
        <h2 class="card-title">{{ __('Logo and favicon') }}</h2>
    </div>
    <div class="card-body">
        <div class="row g-3">
            @foreach (['logo' => [__('Logo'), __('PNG, SVG, JPG or WebP, up to 1 MB. Shown in the sidebar and on the sign-in page.')],
                       'logo_dark' => [__('Logo for dark mode'), __('Optional. A light version of your logo.')],
                       'favicon' => [__('Favicon'), __('PNG, ICO or SVG, square, up to 256 KB.')]] as $kind => [$label, $hint])
                <div class="col-md-4" wire:key="file-{{ $kind }}">
                    <label class="form-label" for="{{ $kind }}">{{ $label }}</label>
                    @if ($files[$kind])
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="ld-brand-file"><img src="{{ $files[$kind] }}" alt=""></span>
                            <button type="button" class="btn btn-sm btn-ghost text-danger" wire:click="removeFile('{{ $kind }}')" wire:confirm="{{ __('Remove the current :item?', ['item' => mb_strtolower($label)]) }}">{{ __('Remove') }}</button>
                        </div>
                    @endif
                    <input id="{{ $kind }}" type="file" wire:model="{{ $kind }}" class="form-control @error($kind) is-invalid @enderror" accept="{{ $kind === 'favicon' ? '.png,.ico,.svg' : '.png,.jpg,.jpeg,.webp,.svg' }}">
                    @error($kind) <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">{{ $hint }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <x-icon name="log-in" size="18" />
        <h2 class="card-title">{{ __('Sign-in page') }}</h2>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4">
            @foreach ($layouts as $key => [$label, $description])
                <div class="col-6 col-md-4 col-xl" wire:key="layout-{{ $key }}">
                    <label @class(['ld-design-option', 'is-selected' => $login_layout === $key])>
                        <input type="radio" class="visually-hidden" value="{{ $key }}" wire:model.live="login_layout">
                        @include('partials.login-thumb', ['design' => $key])
                        <span class="d-flex align-items-center justify-content-between mt-2">
                            <span class="fw-semibold small" style="color: var(--ld-heading)">{{ __($label) }}</span>
                            <a href="{{ $previewRoute($key) }}" target="_blank" rel="noopener" class="small">{{ __('Preview') }}</a>
                        </span>
                        <span class="d-block ld-list__meta">{{ __($description) }}</span>
                    </label>
                </div>
            @endforeach
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="login-headline">{{ __('Headline') }}</label>
                <input id="login-headline" class="form-control" wire:model="login.headline" maxlength="80">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="login-accent">{{ __('Accent word') }} <span class="text-subtle">({{ __('shown in italic') }})</span></label>
                <input id="login-accent" class="form-control" wire:model="login.accent" maxlength="40">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="login-headline-end">{{ __('Headline ending') }}</label>
                <input id="login-headline-end" class="form-control" wire:model="login.headline_end" maxlength="80">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="login-subtitle">{{ __('Subtitle') }}</label>
                <input id="login-subtitle" class="form-control" wire:model="login.subtitle" maxlength="240">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="login-welcome">{{ __('Form heading') }}</label>
                <input id="login-welcome" class="form-control" wire:model="login.welcome" maxlength="60">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="login-note">{{ __('Note under the form') }} <span class="text-subtle">({{ __('optional') }})</span></label>
                <input id="login-note" class="form-control" wire:model="login.note" maxlength="240" placeholder="{{ __('e.g. Need access? Ask the label team.') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="login_image">{{ __('Cover image') }} <span class="text-subtle">({{ __('Cover design') }})</span></label>
                @if ($files['login_image'])
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="ld-brand-file"><img src="{{ $files['login_image'] }}" alt=""></span>
                        <button type="button" class="btn btn-sm btn-ghost text-danger" wire:click="removeFile('login_image')">{{ __('Remove') }}</button>
                    </div>
                @endif
                <input id="login_image" type="file" wire:model="login_image" class="form-control @error('login_image') is-invalid @enderror" accept=".jpg,.jpeg,.png,.webp">
                @error('login_image') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-text mt-2">{{ __('Save first, then use Preview to see your texts and images.') }}</div>
    </div>
</div>

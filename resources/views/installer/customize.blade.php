<x-layouts.installer :title="__('Customise')" :step="$step" wide>
    <div class="eyebrow mb-2">{{ __('Step :n', ['n' => 6]) }}</div>
    <h1>{{ __('Make it yours') }}</h1>
    <p class="ld-installer__lead">{{ __('Name, colours, logo and sign-in page — everything at once. You can change all of it later in Settings.') }}</p>

    @if ($errors->any())
        <x-alert variant="danger" class="mb-4">{{ $errors->first() }}</x-alert>
    @endif

    <form method="POST" action="{{ route('installer.customize.store') }}" enctype="multipart/form-data" novalidate
          x-data="{
              themes: @js($themes),
              theme: @js(old('theme', $v['theme'])),
              custom: @js((bool) old('custom_colors', $v['custom_colors'])),
              c1: @js(old('brand_color', $v['brand_color'])),
              c2: @js(old('brand_color_2', $v['brand_color_2'])),
              modes: @js(old('modes', $v['modes'])),
              defaultMode: @js(old('default_mode', $v['default_mode'])),
              sidebar: @js(old('sidebar', $v['sidebar'])),
              layout: @js(old('login_layout', $v['login_layout'])),
              name: @js(old('name', $v['name'])),
              previewDark: true,
              get dark() { return this.modes === 'dark' || (this.modes === 'both' && this.previewDark); },
              get a1() { return this.custom ? this.c1 : this.themes[this.theme][this.dark ? 3 : 1]; },
              get a2() { return this.custom ? this.c2 : this.themes[this.theme][this.dark ? 4 : 2]; },
              get bg() { return this.themes[this.theme][this.dark ? 6 : 5]; },
              get side() { return this.sidebar === 'dark' ? '#07070b' : (this.sidebar === 'tinted' ? 'color-mix(in oklab, ' + this.a1 + ' 12%, ' + (this.dark ? '#0b0b12' : '#ffffff') + ')' : (this.dark ? '#0b0b12' : '#ffffff')); },
              get ink() { return this.dark || this.sidebar === 'dark' ? '#d2d2e0' : '#2a2a3d'; },
          }">
        @csrf

        <div class="row g-4">
            <div class="col-xl-8">
                {{-- Site --}}
                <h2 class="ld-installer__section"><x-icon name="globe" size="16" /> {{ __('Site') }}</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="name">{{ __('Site name') }}</label>
                        <input id="name" name="name" x-model="name" class="form-control @error('name') is-invalid @enderror" maxlength="80" required>
                        <div class="form-text">{{ __('Shown in the browser tab, emails and the sign-in page — usually your label\'s name.') }}</div>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Site address') }}</label>
                        <input class="form-control font-mono" value="{{ $url }}" readonly aria-readonly="true">
                        <div class="form-text">{{ __('Set with the license in step 0.') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="timezone">{{ __('Time zone') }}</label>
                        <select id="timezone" name="timezone" class="form-select">
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}" @selected(old('timezone', $v['timezone']) === $tz)>{{ $tz }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="currency">{{ __('Currency') }}</label>
                        <select id="currency" name="currency" class="form-select">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}" @selected(old('currency', $v['currency']) === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="date_format">{{ __('Date format') }}</label>
                        <select id="date_format" name="date_format" class="form-select">
                            @foreach ($dateFormats as $format => $example)
                                <option value="{{ $format }}" @selected(old('date_format', $v['date_format']) === $format)>{{ $example }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="locale">{{ __('Language') }}</label>
                        <select id="locale" name="locale" class="form-select">
                            @foreach ($locales as $code => $label)
                                <option value="{{ $code }}" @selected(old('locale', $v['locale']) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Colours --}}
                <h2 class="ld-installer__section"><x-icon name="palette" size="16" /> {{ __('Colours') }}</h2>
                <div class="row g-2 mb-3">
                    @foreach ($themes as $key => [$label, $accent, $accent2, $darkAccent, $darkAccent2])
                        <div class="col-4 col-md-3 col-lg">
                            <label class="ld-option-card text-center p-2" :class="{ 'opacity-50': custom }">
                                <input type="radio" name="theme" value="{{ $key }}" class="visually-hidden" x-model="theme">
                                <span class="d-block rounded-3 mb-1" style="height: 34px; background: linear-gradient(135deg, {{ $darkAccent }}, {{ $darkAccent2 }})"></span>
                                <span class="small fw-semibold" style="color: var(--ld-heading)">{{ __($label) }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="form-check form-switch mb-2">
                    <input type="hidden" name="custom_colors" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="custom_colors" name="custom_colors" value="1" x-model="custom">
                    <label class="form-check-label" for="custom_colors">{{ __('Use my own brand colours') }}</label>
                </div>
                <div class="row g-3 mb-4" x-show="custom" x-cloak>
                    <div class="col-sm-6">
                        <label class="form-label" for="brand_color">{{ __('Primary colour') }}</label>
                        <div class="d-flex gap-2">
                            <input type="color" class="form-control form-control-color" x-model="c1" aria-label="{{ __('Primary colour') }}">
                            <input id="brand_color" name="brand_color" class="form-control font-mono @error('brand_color') is-invalid @enderror" x-model="c1" maxlength="7">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="brand_color_2">{{ __('Accent colour') }}</label>
                        <div class="d-flex gap-2">
                            <input type="color" class="form-control form-control-color" x-model="c2" aria-label="{{ __('Accent colour') }}">
                            <input id="brand_color_2" name="brand_color_2" class="form-control font-mono @error('brand_color_2') is-invalid @enderror" x-model="c2" maxlength="7">
                        </div>
                    </div>
                </div>
                <template x-if="!custom">
                    <div>
                        <input type="hidden" name="brand_color" :value="c1">
                        <input type="hidden" name="brand_color_2" :value="c2">
                    </div>
                </template>

                {{-- Modes, sidebar, font --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label d-block">{{ __('Light and dark mode') }}</label>
                        <div class="ld-segmented" role="radiogroup" aria-label="{{ __('Allowed colour modes') }}">
                            @foreach (['both' => __('Both'), 'light' => __('Light only'), 'dark' => __('Dark only')] as $value => $label)
                                <input type="radio" id="modes-{{ $value }}" name="modes" value="{{ $value }}" x-model="modes">
                                <label for="modes-{{ $value }}">{{ $label }}</label>
                            @endforeach
                        </div>
                        <div class="mt-2" x-show="modes === 'both'">
                            <label class="form-label small" for="default_mode">{{ __('Default') }}</label>
                            <select id="default_mode" name="default_mode" class="form-select form-select-sm" style="max-width: 220px" x-model="defaultMode">
                                <option value="dark">{{ __('Dark') }}</option>
                                <option value="light">{{ __('Light') }}</option>
                                <option value="system">{{ __('Follow the device') }}</option>
                            </select>
                        </div>
                        <template x-if="modes !== 'both'"><input type="hidden" name="default_mode" :value="modes"></template>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block">{{ __('Sidebar') }}</label>
                        <select name="sidebar" class="form-select" x-model="sidebar" aria-label="{{ __('Sidebar style') }}">
                            <option value="light">{{ __('Clean') }}</option>
                            <option value="tinted">{{ __('Tinted') }}</option>
                            <option value="dark">{{ __('Dark') }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block" for="font">{{ __('Font') }}</label>
                        <select id="font" name="font" class="form-select">
                            @foreach ($fonts as $value => $label)
                                <option value="{{ $value }}" @selected(old('font', $v['font']) === $value)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Logo --}}
                <h2 class="ld-installer__section"><x-icon name="image" size="16" /> {{ __('Logo and favicon') }} <span class="text-subtle fw-normal small">({{ __('optional') }})</span></h2>
                <div class="row g-3 mb-4">
                    @foreach (['logo' => [__('Logo'), '.png,.jpg,.jpeg,.webp,.svg'], 'logo_dark' => [__('Logo for dark mode'), '.png,.jpg,.jpeg,.webp,.svg'], 'favicon' => [__('Favicon'), '.png,.ico,.svg']] as $kind => [$label, $accept])
                        <div class="col-md-4">
                            <label class="form-label" for="{{ $kind }}">{{ $label }}</label>
                            @if ($files[$kind])
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="ld-brand-file"><img src="{{ $files[$kind] }}" alt=""></span>
                                    <label class="small"><input type="checkbox" name="remove[]" value="{{ $kind }}" class="form-check-input"> {{ __('Remove') }}</label>
                                </div>
                            @endif
                            <input id="{{ $kind }}" name="{{ $kind }}" type="file" accept="{{ $accept }}" class="form-control @error($kind) is-invalid @enderror">
                            @error($kind) <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>

                {{-- Sign-in page --}}
                <h2 class="ld-installer__section"><x-icon name="log-in" size="16" /> {{ __('Sign-in page') }}</h2>
                <div class="row g-2 mb-3">
                    @foreach ($layouts as $key => [$label, $description])
                        <div class="col-6 col-md">
                            <label class="ld-option-card p-2">
                                <input type="radio" name="login_layout" value="{{ $key }}" class="visually-hidden" x-model="layout">
                                @include('partials.login-thumb', ['design' => $key])
                                <span class="d-block small fw-semibold mt-2" style="color: var(--ld-heading)">{{ __($label) }}</span>
                                <span class="d-block ld-list__meta">{{ __($description) }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="login-headline">{{ __('Headline') }}</label>
                        <input id="login-headline" name="login[headline]" class="form-control" value="{{ old('login.headline', $v['login']['headline']) }}" maxlength="80">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="login-accent">{{ __('Accent word') }} <span class="text-subtle">({{ __('italic') }})</span></label>
                        <input id="login-accent" name="login[accent]" class="form-control" value="{{ old('login.accent', $v['login']['accent']) }}" maxlength="40">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="login-headline-end">{{ __('Headline ending') }}</label>
                        <input id="login-headline-end" name="login[headline_end]" class="form-control" value="{{ old('login.headline_end', $v['login']['headline_end']) }}" maxlength="80">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="login-subtitle">{{ __('Subtitle') }}</label>
                        <input id="login-subtitle" name="login[subtitle]" class="form-control" value="{{ old('login.subtitle', $v['login']['subtitle']) }}" maxlength="240">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="login-welcome">{{ __('Form heading') }}</label>
                        <input id="login-welcome" name="login[welcome]" class="form-control" value="{{ old('login.welcome', $v['login']['welcome']) }}" maxlength="60">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="login-note">{{ __('Note under the form') }} <span class="text-subtle">({{ __('optional') }})</span></label>
                        <input id="login-note" name="login[note]" class="form-control" value="{{ old('login.note', $v['login']['note']) }}" maxlength="240" placeholder="{{ __('e.g. Need access? Ask the label team.') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="login_image">{{ __('Cover image') }} <span class="text-subtle">({{ __('Cover design') }})</span></label>
                        @if ($files['login_image'])
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="ld-brand-file"><img src="{{ $files['login_image'] }}" alt=""></span>
                                <label class="small"><input type="checkbox" name="remove[]" value="login_image" class="form-check-input"> {{ __('Remove') }}</label>
                            </div>
                        @endif
                        <input id="login_image" name="login_image" type="file" accept=".jpg,.jpeg,.png,.webp" class="form-control @error('login_image') is-invalid @enderror">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="registration" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="registration" name="registration" value="1" @checked(old('registration', $v['registration']))>
                            <label class="form-check-label" for="registration">{{ __('Allow artists and labels to sign up') }}</label>
                        </div>
                        <div class="form-text">{{ __('Shows "Create an account" on the sign-in page. New accounts wait for an administrator\'s approval. You can change this later in Settings → General.') }}</div>
                    </div>
                    <div class="col-12" x-data="{ site: {{ old('website', $v['website']) ? 'true' : 'false' }} }">
                        <div class="form-check form-switch">
                            <input type="hidden" name="website" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="website" name="website" value="1" x-model="site" @checked(old('website', $v['website']))>
                            <label class="form-check-label" for="website">{{ __('Label website') }}</label>
                        </div>
                        <div class="form-text">{{ __('Shows a public website for your label (music, artists and your own pages) at the address of this installation. Off: the address opens the sign-in page, for labels with their own website. You can change everything later in Settings → Website.') }}</div>
                        <div x-show="site" x-cloak class="mt-3">
                            <label class="form-label">{{ __('Website theme') }}</label>
                            <div class="row g-2 mb-3">
                                @foreach ($websiteThemes as $key => [$name, $description, $mode, $bg, $fg])
                                    <div class="col-6 col-md-4 col-xl-3">
                                        <input type="radio" class="btn-check" name="website_theme" id="wt-{{ $key }}" value="{{ $key }}" @checked(old('website_theme', $v['website_theme']) === $key)>
                                        <label class="btn btn-light w-100 text-start h-100" for="wt-{{ $key }}">
                                            <span class="d-block rounded mb-2" style="height: 28px; background: {{ $bg }}; border: 1px solid {{ $fg }}33"></span>
                                            <span class="fw-semibold d-block">{{ __($name) }}</span>
                                            <span class="small text-muted d-block" style="white-space: normal">{{ __($description) }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            @error('website_theme') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label" for="website_title">{{ __('Headline') }}</label>
                                    <input id="website_title" name="website_title" maxlength="120" value="{{ old('website_title', $v['website_title']) }}" placeholder="{{ __('Your label name') }}" class="form-control @error('website_title') is-invalid @enderror">
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label" for="website_text">{{ __('Introduction') }}</label>
                                    <input id="website_text" name="website_text" maxlength="300" value="{{ old('website_text', $v['website_text']) }}" class="form-control @error('website_text') is-invalid @enderror">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Live preview --}}
            <div class="col-xl-4">
                <div class="position-sticky" style="top: 1.5rem">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="eyebrow">{{ __('Live preview') }}</span>
                        <div class="btn-group btn-group-sm" x-show="modes === 'both'">
                            <button type="button" class="btn btn-sm" :class="previewDark ? 'btn-light' : 'btn-ghost'" @click="previewDark = true"><x-icon name="moon" size="13" /></button>
                            <button type="button" class="btn btn-sm" :class="!previewDark ? 'btn-light' : 'btn-ghost'" @click="previewDark = false"><x-icon name="sun" size="13" /></button>
                        </div>
                    </div>
                    <div class="ld-mini-app" :style="`background: ${bg}`">
                        <div class="ld-mini-app__side" :style="`background: ${side}; color: ${ink}; border-right: 1px solid ${dark ? 'rgba(255,255,255,.06)' : 'rgba(16,16,40,.08)'}`">
                            <div class="d-flex align-items-center gap-1 fw-semibold mb-2" :style="`color: ${dark || sidebar === 'dark' ? '#fff' : '#0b0b12'}`">
                                <span class="d-inline-block rounded-2 flex-shrink-0" :style="`width: 16px; height: 16px; background: linear-gradient(135deg, ${a1}, ${a2})`"></span>
                                <span class="text-truncate" x-text="name"></span>
                            </div>
                            <span class="ld-mini-app__nav" :style="`background: ${dark || sidebar === 'dark' ? 'rgba(255,255,255,.07)' : 'rgba(16,16,40,.05)'}; color: ${a1}`">● {{ __('Dashboard') }}</span>
                            <span class="ld-mini-app__nav">{{ __('Releases') }}</span>
                            <span class="ld-mini-app__nav">{{ __('Royalties') }}</span>
                        </div>
                        <div class="ld-mini-app__main" :style="`color: ${dark ? '#d2d2e0' : '#2a2a3d'}`">
                            <div :style="`font-size: 15px; font-weight: 600; letter-spacing: -.03em; color: ${dark ? '#fff' : '#0b0b12'}`">
                                {{ __('Good morning,') }} <span :style="`font-family: var(--ld-font-serif); font-style: italic; font-weight: 400; color: ${a2}`">{{ __('team') }}</span>
                            </div>
                            <div class="ld-mini-app__card" :style="`background: ${dark ? 'rgba(255,255,255,.04)' : '#fff'}; border: 1px solid ${dark ? 'rgba(255,255,255,.06)' : 'rgba(16,16,40,.08)'}`">
                                <div style="opacity: .7">{{ __('Releases') }}</div>
                                <div :style="`font-size: 18px; font-weight: 600; color: ${dark ? '#fff' : '#0b0b12'}`">24</div>
                                <div class="mt-1 rounded-pill" :style="`height: 4px; background: linear-gradient(90deg, ${a1}, ${a2})`"></div>
                            </div>
                            <div><span class="ld-mini-app__btn" :style="`background: linear-gradient(180deg, ${a1}, color-mix(in oklab, ${a1} 80%, black))`">{{ __('New release') }}</span></div>
                        </div>
                    </div>
                    <p class="small text-muted mt-2 mb-3">{{ __('Sample screen. Your real data appears after installation.') }}</p>

                    <button type="submit" name="action" value="preview" formtarget="_blank" class="btn btn-light w-100">
                        <x-icon name="eye" size="16" /> {{ __('Preview the sign-in page') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="ld-installer__actions">
            <a href="{{ route('installer.administrator') }}" class="btn btn-ghost"><x-icon name="arrow-left" size="16" /> {{ __('Back') }}</a>
            <button type="submit" name="action" value="save" class="btn btn-primary">{{ __('Save & review') }} <x-icon name="arrow-right" size="16" /></button>
        </div>
    </form>
</x-layouts.installer>

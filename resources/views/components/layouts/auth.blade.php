@props(['title' => null, 'design' => null, 'texts' => null, 'preview' => false])
@php
    $ldDesign = array_key_exists((string) $design, App\Support\Appearance::LOGIN_LAYOUTS) ? $design : App\Support\Appearance::loginLayout();
    $ldTexts = $texts ?? App\Support\Appearance::loginTexts();
    $ldImage = App\Support\Branding::url('login_image');
    $ldAppName = config('app.name');
    $ldHeadline = function () use ($ldTexts) {
        return e($ldTexts['headline']).' <span class="serif-accent text-gradient">'.e($ldTexts['accent']).'</span> '.e($ldTexts['headline_end']);
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="ld-no-transition"
      data-theme-preference="{{ $themePreference }}"
      @unless ($appearance['toggle']) data-theme-locked="1" @endunless
      data-ld-theme="{{ $appearance['theme'] }}"
      data-ld-font="{{ $appearance['font'] }}"
      data-ld-area="{{ $appearance['area'] }}"
      data-bs-theme="light">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="ld-login ld-login--{{ $ldDesign }}">
    @if ($preview)
        <div class="ld-login__preview-bar">{{ __('Preview of the ":design" sign-in page. The form is disabled.', ['design' => __(App\Support\Appearance::LOGIN_LAYOUTS[$ldDesign][0])]) }}</div>
    @endif

    @switch($ldDesign)
        @case('split')
            <div class="ld-login__split">
                <section class="ld-login__story" aria-hidden="true">
                    <div class="ld-login__glow ld-login__glow--a"></div>
                    <div class="ld-login__glow ld-login__glow--b"></div>
                    <div class="ld-login__brand"><x-logo size="34" /> <span>{{ $ldAppName }}</span></div>
                    <div>
                        <div class="ld-login__eq">
                            @foreach ([40, 70, 55, 90, 62, 100, 48, 80, 58, 72, 44, 66] as $i => $h)
                                <span class="eq-bar" style="height: {{ $h }}%; animation-delay: -{{ $i * 0.13 }}s"></span>
                            @endforeach
                        </div>
                        <h2 class="ld-login__headline">{!! $ldHeadline() !!}</h2>
                        <p class="ld-login__subtitle">{{ $ldTexts['subtitle'] }}</p>
                    </div>
                    <div class="ld-login__meta">{{ config('labeldesk.product.name') }} {{ config('labeldesk.product.version') }}</div>
                </section>
                <main class="ld-login__panel">
                    <div class="ld-login__form">
                        <div class="ld-login__brand d-lg-none mb-4"><x-logo size="30" /> <span>{{ $ldAppName }}</span></div>
                        {{ $slot }}
                        @include('components.layouts.partials.login-note')
                    </div>
                </main>
            </div>
            @break

        @case('cover')
            <div class="ld-login__cover" @if ($ldImage) style="background-image: url('{{ $ldImage }}')" @endif>
                <div class="ld-login__cover-shade" aria-hidden="true"></div>
                @unless ($ldImage)
                    <div class="ld-login__cover-art" aria-hidden="true">
                        <div class="ld-login__glow ld-login__glow--a"></div>
                        <div class="ld-login__glow ld-login__glow--b"></div>
                        <div class="grid-bg position-absolute top-0 start-0 w-100 h-100"></div>
                    </div>
                @endunless
                <div class="ld-login__cover-copy" aria-hidden="true">
                    <div class="ld-login__brand mb-4"><x-logo size="34" /> <span>{{ $ldAppName }}</span></div>
                    <h2 class="ld-login__headline">{!! $ldHeadline() !!}</h2>
                    <p class="ld-login__subtitle">{{ $ldTexts['subtitle'] }}</p>
                </div>
                <main class="ld-login__cover-card glass">
                    {{ $slot }}
                    @include('components.layouts.partials.login-note')
                </main>
            </div>
            @break

        @case('studio')
            <div class="ld-login__split ld-login__split--reverse">
                <main class="ld-login__panel">
                    <div class="ld-login__form">
                        <div class="ld-login__brand mb-5"><x-logo size="30" /> <span>{{ $ldAppName }}</span></div>
                        {{ $slot }}
                        @include('components.layouts.partials.login-note')
                    </div>
                </main>
                <section class="ld-login__showcase" aria-hidden="true">
                    <div class="grid-bg position-absolute top-0 start-0 w-100 h-100"></div>
                    <div class="ld-login__glow ld-login__glow--a"></div>
                    <div class="ld-login__glow ld-login__glow--b"></div>
                    <div class="ld-login__showcase-inner">
                        <h2 class="ld-login__headline">{!! $ldHeadline() !!}</h2>
                        <p class="ld-login__subtitle">{{ $ldTexts['subtitle'] }}</p>
                        <div class="ld-login__player glass animate-float">
                            <div class="ld-login__cover-thumb"></div>
                            <div class="flex-grow-1">
                                <div class="eyebrow mb-1">{{ __('Now in review') }}</div>
                                <div class="fw-semibold text-white">{{ __('Your next release') }}</div>
                                <div class="ld-login__eq ld-login__eq--small mt-2">
                                    @foreach ([50, 80, 40, 95, 60, 75, 45, 85, 55] as $i => $h)
                                        <span class="eq-bar" style="height: {{ $h }}%; animation-delay: -{{ $i * 0.17 }}s"></span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @foreach ([['disc-3', __('Releases')], ['send', __('Distribution')], ['wallet', __('Royalties')], ['file-signature', __('Contracts')]] as [$icon, $label])
                                <span class="ld-login__chip glass"><x-icon :name="$icon" size="14" /> {{ $label }}</span>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>
            @break

        @case('minimal')
            <main class="ld-login__minimal">
                <div class="ld-login__form">
                    <div class="ld-login__brand justify-content-center mb-4"><x-logo size="36" /></div>
                    {{ $slot }}
                    @include('components.layouts.partials.login-note')
                </div>
            </main>
            @break

        @default
            {{-- spotlight --}}
            <div class="ld-login__spotlight">
                <div class="grid-bg ld-login__grid" aria-hidden="true"></div>
                <div class="ld-login__glow ld-login__glow--a" aria-hidden="true"></div>
                <div class="ld-login__glow ld-login__glow--b" aria-hidden="true"></div>
                <div class="ld-login__spotlight-inner">
                    <div class="ld-login__brand justify-content-center mb-4"><x-logo size="40" /> <span>{{ $ldAppName }}</span></div>
                    <h2 class="ld-login__headline text-center" aria-hidden="true">{!! $ldHeadline() !!}</h2>
                    <main class="ld-login__card card mt-4">
                        <div class="card-body p-4 p-sm-5">
                            {{ $slot }}
                        </div>
                    </main>
                    @include('components.layouts.partials.login-note', ['center' => true])
                </div>
            </div>
    @endswitch

    @include('partials.flash')
</body>
</html>

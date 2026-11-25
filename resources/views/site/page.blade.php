@php
    $md = fn (string $text) => \Illuminate\Support\Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    $intro = $page->text('intro');
@endphp
<x-layouts.site :title="$page->title" :settings="$settings" :description="$page->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($md($intro ?: $page->text('body'))), 155)">
    <section class="ld-site-page-head">
        <div class="ld-site__container">
            <div class="ld-site__eyebrow">{{ config('app.name') }}</div>
            <h1>{{ $page->title }}</h1>
            @if ($intro)
                <div class="ld-site-page-head__intro ld-site-prose">{!! $md($intro) !!}</div>
            @endif
        </div>
    </section>

    <section class="ld-site-section ld-site-section--tight">
        <div class="ld-site__container">
            @switch($page->type)
                @case('services')
                    <div class="ld-site-services">
                        @foreach ($page->items('items') as $item)
                            <article class="ld-site-service">
                                <span class="ld-site-service__no">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <h2>{{ $item['title'] ?? '' }}</h2>
                                @if (! empty($item['text'])) <div class="ld-site-prose">{!! $md((string) $item['text']) !!}</div> @endif
                            </article>
                        @endforeach
                    </div>
                    @if ($page->text('outro')) <div class="ld-site-prose mt-5">{!! $md($page->text('outro')) !!}</div> @endif
                    @break

                @case('pricing')
                    <div class="ld-site-pricing">
                        @foreach ($page->items('plans') as $plan)
                            <article @class(['ld-site-plan', 'ld-site-plan--highlight' => ! empty($plan['highlight'])])>
                                @if (! empty($plan['highlight'])) <span class="ld-site-plan__badge">{{ __('Most popular') }}</span> @endif
                                <h2 class="ld-site-plan__name">{{ $plan['name'] ?? '' }}</h2>
                                <div class="ld-site-plan__price">
                                    <span>{{ $plan['price'] ?? '' }}</span>
                                    @if (! empty($plan['period'])) <small>/ {{ $plan['period'] }}</small> @endif
                                </div>
                                @if (! empty($plan['text'])) <p class="ld-site__muted">{{ $plan['text'] }}</p> @endif
                                @php($features = array_filter(array_map('trim', preg_split('/\R/', (string) ($plan['features'] ?? '')))))
                                @if ($features)
                                    <ul class="ld-site-plan__features">
                                        @foreach ($features as $feature)
                                            <li><x-icon name="check" size="16" /> {{ $feature }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if (! empty($plan['cta_label']) && ! empty($plan['cta_url']))
                                    <a href="{{ $plan['cta_url'] }}" @class(['btn w-100 mt-auto', 'btn-primary' => ! empty($plan['highlight']), 'btn-light' => empty($plan['highlight'])])
                                       @if (str_starts_with($plan['cta_url'], 'http')) target="_blank" rel="noopener" @endif>{{ $plan['cta_label'] }}</a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                    @if ($page->text('note')) <div class="ld-site-prose ld-site__muted small mt-4">{!! $md($page->text('note')) !!}</div> @endif
                    @break

                @case('contact')
                    <div class="ld-site-contact ld-site-contact--page">
                        <ul class="ld-site-contact__list">
                            @if ($settings['contact_email'])
                                <li><x-icon name="mail" size="18" /> <a href="mailto:{{ $settings['contact_email'] }}">{{ $settings['contact_email'] }}</a></li>
                            @endif
                            @if ($settings['contact_phone'])
                                <li><x-icon name="phone" size="18" /> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['contact_phone']) }}">{{ $settings['contact_phone'] }}</a></li>
                            @endif
                            @if ($settings['contact_address'])
                                <li><x-icon name="map-pin" size="18" /> <span>{{ $settings['contact_address'] }}</span></li>
                            @endif
                        </ul>
                        @if ($page->content['form'] ?? true)
                            @if (session('sent'))
                                <div class="alert alert-success mb-0" role="status">{{ __('Thank you — your message has been sent. We will get back to you soon.') }}</div>
                            @else
                                <form method="POST" action="{{ route('site.contact.send') }}" class="ld-site-form" novalidate>
                                    @csrf
                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <label class="form-label" for="c-name">{{ __('Your name') }}</label>
                                            <input id="c-name" name="name" value="{{ old('name') }}" maxlength="120" required autocomplete="name" @class(['form-control', 'is-invalid' => $errors->has('name')])>
                                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label" for="c-email">{{ __('E-mail') }}</label>
                                            <input id="c-email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email" @class(['form-control', 'is-invalid' => $errors->has('email')])>
                                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="c-subject">{{ __('Subject') }}</label>
                                            <input id="c-subject" name="subject" value="{{ old('subject') }}" maxlength="150" @class(['form-control', 'is-invalid' => $errors->has('subject')])>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="c-message">{{ __('Message') }}</label>
                                            <textarea id="c-message" name="message" rows="6" maxlength="5000" required @class(['form-control', 'is-invalid' => $errors->has('message')])>{{ old('message') }}</textarea>
                                            @error('message') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div aria-hidden="true" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden">
                                            <label for="c-website">{{ __('Leave this field empty') }}</label>
                                            <input id="c-website" name="website_url" tabindex="-1" autocomplete="off">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary btn-lg">{{ __('Send message') }} <x-icon name="send" size="16" /></button>
                                        </div>
                                    </div>
                                </form>
                            @endif
                        @endif
                    </div>
                    @break

                @default
                    <div class="ld-site-prose ld-site-prose--page">{!! $md($page->text('body')) !!}</div>
            @endswitch
        </div>
    </section>
</x-layouts.site>

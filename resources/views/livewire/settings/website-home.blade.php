@php
    $linkHint = __('A page address such as /pricing or /contact, an https:// link or a mailto: address.');
@endphp
<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')
    @include('livewire.settings._website-nav')

    <p class="text-muted small mb-3">{{ __('Optional sections of the front page, in the order they appear. A section stays hidden until it has content. Headline, introduction and featured release are under “Website and theme”.') }}</p>

    <form wire:submit="save" novalidate>
        {{-- Hero --}}
        <div class="card mb-3">
            <div class="card-header"><x-icon name="sparkles" size="18" /><h2 class="card-title">{{ __('Top of the page') }}</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="h-eyebrow">{{ __('Small line above the headline') }}</label>
                        <input id="h-eyebrow" class="form-control @error('home.eyebrow') is-invalid @enderror" wire:model="home.eyebrow" maxlength="60" placeholder="{{ __('Record label') }}">
                    </div>
                    @foreach ([1 => __('Main button'), 2 => __('Second button')] as $n => $label)
                        <div class="col-md-4">
                            <label class="form-label" for="h-cta{{ $n }}">{{ $label }}</label>
                            <div class="input-group">
                                <input id="h-cta{{ $n }}" class="form-control @error('home.cta'.$n.'_label') is-invalid @enderror" wire:model="home.cta{{ $n }}_label" maxlength="40" placeholder="{{ __('Text') }}">
                                <input class="form-control font-mono @error('home.cta'.$n.'_url') is-invalid @enderror" wire:model="home.cta{{ $n }}_url" maxlength="255" placeholder="/contact" aria-label="{{ __('Link') }}">
                            </div>
                            @error('home.cta'.$n.'_label') <div class="small text-danger">{{ $message }}</div> @enderror
                            @error('home.cta'.$n.'_url') <div class="small text-danger">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="form-text">{{ __('Without buttons, “Explore the music” and “Meet the artists” are shown.') }} {{ $linkHint }}</div>
            </div>
        </div>

        {{-- Stores --}}
        <div class="card mb-3">
            <div class="card-header"><x-icon name="store" size="18" /><h2 class="card-title">{{ __('Stores strip') }}</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="h-stores-title">{{ __('Title') }}</label>
                        <input id="h-stores-title" class="form-control" wire:model="home.stores_title" maxlength="120" placeholder="{{ __('Your music, everywhere') }}">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="h-stores">{{ __('Store and platform names') }}</label>
                        <textarea id="h-stores" rows="2" class="form-control @error('home.stores') is-invalid @enderror" wire:model="home.stores" maxlength="2000" placeholder="Spotify, Apple Music, YouTube Music, Amazon Music, JioSaavn"></textarea>
                        <div class="form-text">{{ __('Separated by commas or one per line. Shown as a moving strip of names.') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Repeaters --}}
        @foreach ([
            'stats' => ['icon' => 'chart-column', 'title' => __('Numbers'), 'add' => __('Add number'), 'titleKey' => null,
                'hint' => __('Up to 4, e.g. “150+” stores, “10K+” releases. Without numbers, your release and artist counts are shown in the top section.')],
            'steps' => ['icon' => 'list-ordered', 'title' => __('How it works'), 'add' => __('Add step'), 'titleKey' => 'steps_title', 'hint' => __('Up to 6 numbered steps.')],
            'testimonials' => ['icon' => 'quote', 'title' => __('Testimonials'), 'add' => __('Add testimonial'), 'titleKey' => 'testimonials_title', 'hint' => __('Quotes from artists you work with — with their permission.')],
            'faq' => ['icon' => 'circle-help', 'title' => __('Frequently asked questions'), 'add' => __('Add question'), 'titleKey' => 'faq_title', 'hint' => __('Shown as an expandable list.')],
        ] as $list => $cfg)
            <div class="card mb-3" wire:key="list-{{ $list }}">
                <div class="card-header">
                    <x-icon :name="$cfg['icon']" size="18" />
                    <h2 class="card-title">{{ $cfg['title'] }}</h2>
                    <button type="button" class="btn btn-sm btn-light ms-auto" wire:click="addRow('{{ $list }}')"><x-icon name="plus" size="14" /> {{ $cfg['add'] }}</button>
                </div>
                <div class="card-body">
                    @if ($cfg['titleKey'])
                        <div class="mb-3" style="max-width: 420px">
                            <label class="form-label small" for="h-{{ $list }}-title">{{ __('Section title') }}</label>
                            <input id="h-{{ $list }}-title" class="form-control form-control-sm" wire:model="home.{{ $cfg['titleKey'] }}" maxlength="120" placeholder="{{ $cfg['title'] }}">
                        </div>
                    @endif
                    @forelse ($home[$list] ?? [] as $i => $item)
                        <div class="border rounded-3 p-2 mb-2 d-flex gap-2 align-items-start" wire:key="{{ $list }}-{{ $i }}">
                            <div class="flex-grow-1 row g-2">
                                @switch($list)
                                    @case('stats')
                                        <div class="col-4"><input class="form-control @error("home.stats.$i.value") is-invalid @enderror" wire:model="home.stats.{{ $i }}.value" maxlength="20" placeholder="150+" aria-label="{{ __('Number') }}"></div>
                                        <div class="col-8"><input class="form-control @error("home.stats.$i.label") is-invalid @enderror" wire:model="home.stats.{{ $i }}.label" maxlength="60" placeholder="{{ __('stores and platforms') }}" aria-label="{{ __('Label') }}"></div>
                                        @break
                                    @case('steps')
                                        <div class="col-md-4"><input class="form-control @error("home.steps.$i.title") is-invalid @enderror" wire:model="home.steps.{{ $i }}.title" maxlength="80" placeholder="{{ __('Step title') }}" aria-label="{{ __('Step title') }}"></div>
                                        <div class="col-md-8"><input class="form-control" wire:model="home.steps.{{ $i }}.text" maxlength="400" placeholder="{{ __('One or two sentences') }}" aria-label="{{ __('Description') }}"></div>
                                        @break
                                    @case('testimonials')
                                        <div class="col-12"><textarea rows="2" class="form-control @error("home.testimonials.$i.quote") is-invalid @enderror" wire:model="home.testimonials.{{ $i }}.quote" maxlength="600" placeholder="{{ __('Quote') }}" aria-label="{{ __('Quote') }}"></textarea></div>
                                        <div class="col-6"><input class="form-control @error("home.testimonials.$i.name") is-invalid @enderror" wire:model="home.testimonials.{{ $i }}.name" maxlength="80" placeholder="{{ __('Name') }}" aria-label="{{ __('Name') }}"></div>
                                        <div class="col-6"><input class="form-control" wire:model="home.testimonials.{{ $i }}.role" maxlength="80" placeholder="{{ __('e.g. Singer-songwriter') }}" aria-label="{{ __('Role') }}"></div>
                                        @break
                                    @case('faq')
                                        <div class="col-12"><input class="form-control @error("home.faq.$i.q") is-invalid @enderror" wire:model="home.faq.{{ $i }}.q" maxlength="200" placeholder="{{ __('Question') }}" aria-label="{{ __('Question') }}"></div>
                                        <div class="col-12"><textarea rows="2" class="form-control @error("home.faq.$i.a") is-invalid @enderror" wire:model="home.faq.{{ $i }}.a" maxlength="2000" placeholder="{{ __('Answer') }}" aria-label="{{ __('Answer') }}"></textarea></div>
                                        @break
                                @endswitch
                            </div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-light btn-sm" wire:click="moveRow('{{ $list }}', {{ $i }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="14" /></button>
                                <button type="button" class="btn btn-light btn-sm" wire:click="moveRow('{{ $list }}', {{ $i }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="14" /></button>
                                <button type="button" class="btn btn-light btn-sm text-danger" wire:click="removeRow('{{ $list }}', {{ $i }})" aria-label="{{ __('Remove') }}"><x-icon name="x" size="14" /></button>
                            </div>
                        </div>
                    @empty
                        <p class="small text-muted mb-0">{{ __('Nothing yet — this section is hidden.') }}</p>
                    @endforelse
                    @if ($errors->has('home.'.$list.'.*'))
                        <div class="small text-danger mt-1">{{ __('Fill in the highlighted fields or remove those rows.') }}</div>
                    @endif
                    <div class="form-text">{{ $cfg['hint'] }}</div>
                </div>
            </div>

            @if ($list === 'stats')
                {{-- Services teaser sits after the numbers --}}
                <div class="card mb-3">
                    <div class="card-header"><x-icon name="layout-grid" size="18" /><h2 class="card-title">{{ __('Services') }}</h2></div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="h-services" wire:model="home.show_services">
                            <label class="form-check-label" for="h-services">{{ __('Show the services from the Services page') }}</label>
                        </div>
                        <input class="form-control form-control-sm" style="max-width: 420px" wire:model="home.services_title" maxlength="120" placeholder="{{ __('What we do') }}" aria-label="{{ __('Section title') }}">
                        <div class="form-text">
                            @if ($servicesPage?->is_published && $servicesPage->items('items'))
                                {{ trans_choice(':count service from the Services page is shown.|:count services from the Services page are shown.', count($servicesPage->items('items'))) }}
                            @else
                                {{ __('Publish the Services page with at least one service to show this section.') }}
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Closing call to action --}}
        <div class="card mb-3">
            <div class="card-header"><x-icon name="megaphone" size="18" /><h2 class="card-title">{{ __('Closing call to action') }}</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="h-cta-title">{{ __('Title') }}</label>
                        <input id="h-cta-title" class="form-control @error('home.cta_title') is-invalid @enderror" wire:model="home.cta_title" maxlength="120" placeholder="{{ __('Ready to release your music?') }}">
                        @error('home.cta_title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="h-cta-text">{{ __('Text') }}</label>
                        <input id="h-cta-text" class="form-control" wire:model="home.cta_text" maxlength="300">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="h-cta-btn">{{ __('Button') }}</label>
                        <div class="input-group">
                            <input id="h-cta-btn" class="form-control @error('home.cta_label') is-invalid @enderror" wire:model="home.cta_label" maxlength="40" placeholder="{{ __('Text') }}">
                            <input class="form-control font-mono @error('home.cta_url') is-invalid @enderror" wire:model="home.cta_url" maxlength="255" placeholder="/contact" aria-label="{{ __('Link') }}">
                        </div>
                        @error('home.cta_label') <div class="small text-danger">{{ $message }}</div> @enderror
                        @error('home.cta_url') <div class="small text-danger">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ __('Save home page') }}
            </button>
        </div>
    </form>
</div>

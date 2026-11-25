<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <form wire:submit="save" novalidate>
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <span @class(['ld-list__icon', 'ld-list__icon--success' => $enabled])><x-icon name="globe" size="18" /></span>
                <div class="flex-grow-1">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="enabled" wire:model.live="enabled">
                        <label class="form-check-label fw-semibold" for="enabled" style="color: var(--ld-heading)">{{ __('Label website') }}</label>
                    </div>
                    <div class="small text-muted">
                        @if ($enabled)
                            {{ __(':url shows your label’s website; the team signs in at :login.', ['url' => $homeUrl, 'login' => route('login')]) }}
                        @else
                            {{ __('Off: :url opens the sign-in page. Choose this if your label has its own website.', ['url' => $homeUrl]) }}
                        @endif
                    </div>
                </div>
                @if ($enabled)
                    <a href="{{ $homeUrl }}" target="_blank" rel="noopener" class="btn btn-light"><x-icon name="external-link" size="16" /> {{ __('Open website') }}</a>
                @endif
            </div>
            <div class="card-footer small text-muted">
                {{ __('Colours, fonts, logo and light/dark mode follow Settings → Appearance. The website shows :releases released releases and :artists artists. Only approved or ready releases appear; contact details, notes, contracts and earnings are never shown.', ['releases' => $publicReleases, 'artists' => $publicArtists]) }}
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="sparkles" size="18" />
                        <h2 class="card-title">{{ __('Front page') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="hero_title">{{ __('Headline') }}</label>
                                <input id="hero_title" class="form-control @error('hero_title') is-invalid @enderror" wire:model="hero_title" maxlength="120" placeholder="{{ config('app.name') }}">
                                @error('hero_title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="hero_text">{{ __('Introduction') }}</label>
                                <textarea id="hero_text" rows="2" class="form-control @error('hero_text') is-invalid @enderror" wire:model="hero_text" maxlength="300" placeholder="{{ __('Independent music, made with care.') }}"></textarea>
                                @error('hero_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="featured_release_id">{{ __('Featured release') }}</label>
                                <select id="featured_release_id" class="form-select @error('featured_release_id') is-invalid @enderror" wire:model="featured_release_id">
                                    <option value="">{{ __('The latest release') }}</option>
                                    @foreach ($releases as $release)
                                        <option value="{{ $release->id }}">{{ $release->displayTitle() }} · {{ $release->release_date?->format(config('labeldesk.date_format', 'd M Y')) }}</option>
                                    @endforeach
                                </select>
                                @error('featured_release_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="hero_use_login_image" wire:model="hero_use_login_image" @disabled(! $loginImage)>
                                    <label class="form-check-label" for="hero_use_login_image">{{ __('Use the sign-in image as background') }}</label>
                                    @unless ($loginImage) <div class="form-text">{{ __('Upload one under Appearance first.') }}</div> @endunless
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="book-open" size="18" />
                        <h2 class="card-title">{{ __('About the label') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="about_title">{{ __('Title') }}</label>
                            <input id="about_title" class="form-control @error('about_title') is-invalid @enderror" wire:model="about_title" maxlength="120" placeholder="{{ __('About :name', ['name' => config('app.name')]) }}">
                            @error('about_title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <label class="form-label" for="about_text">{{ __('Text') }}</label>
                        <textarea id="about_text" rows="6" class="form-control @error('about_text') is-invalid @enderror" wire:model="about_text" maxlength="5000"></textarea>
                        @error('about_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">{{ __('Plain text; empty lines start a new paragraph.') }}</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <x-icon name="at-sign" size="18" />
                        <h2 class="card-title">{{ __('Contact and social media') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="contact_email">{{ __('Public e-mail') }}</label>
                                <input id="contact_email" type="email" class="form-control @error('contact_email') is-invalid @enderror" wire:model="contact_email" maxlength="255" placeholder="hello@yourlabel.com">
                                @error('contact_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contact_phone">{{ __('Phone') }}</label>
                                <input id="contact_phone" class="form-control @error('contact_phone') is-invalid @enderror" wire:model="contact_phone" maxlength="40">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="contact_address">{{ __('Address') }}</label>
                                <input id="contact_address" class="form-control @error('contact_address') is-invalid @enderror" wire:model="contact_address" maxlength="300">
                            </div>
                            @foreach ($networks as $network => $label)
                                <div class="col-md-6">
                                    <label class="form-label" for="social-{{ $network }}">{{ $label }}</label>
                                    <input id="social-{{ $network }}" type="url" class="form-control @error('socials.'.$network) is-invalid @enderror" wire:model="socials.{{ $network }}" maxlength="255" placeholder="https://">
                                    @error('socials.'.$network) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="layout-template" size="18" />
                        <h2 class="card-title">{{ __('Sections') }}</h2>
                    </div>
                    <div class="card-body">
                        @foreach ([
                            'show_releases' => __('Releases'),
                            'show_upcoming' => __('Upcoming releases (approved, release date still to come)'),
                            'show_artists' => __('Artists'),
                            'show_about' => __('About the label'),
                            'show_contact' => __('Contact'),
                            'show_login_link' => __('“Sign in” link for your team'),
                        ] as $key => $label)
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="{{ $key }}" wire:model="{{ $key }}">
                                <label class="form-check-label" for="{{ $key }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <x-icon name="search" size="18" />
                        <h2 class="card-title">{{ __('Search engines') }}</h2>
                    </div>
                    <div class="card-body">
                        <label class="form-label" for="meta_description">{{ __('Description') }}</label>
                        <textarea id="meta_description" rows="3" class="form-control @error('meta_description') is-invalid @enderror" wire:model="meta_description" maxlength="160"></textarea>
                        @error('meta_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">{{ __('Up to 160 characters, shown under your name in search results.') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mt-4">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ __('Save website') }}
            </button>
        </div>
    </form>
</div>

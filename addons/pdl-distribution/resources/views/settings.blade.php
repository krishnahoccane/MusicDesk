<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                {{-- Connection --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="plug" size="18" />
                        <h2 class="card-title">{{ __('PDL COSMOS connection') }}</h2>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="pdl-enabled" wire:model="enabled">
                            <label class="form-check-label" for="pdl-enabled">{{ __('Enabled') }}</label>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">{{ __('Releases are sent with the COSMOS data ingestion API of Phonographic Digital Limited. Use the account PDL created for your label.') }}</p>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="pdl-base">{{ __('API base URL') }}</label>
                                <input id="pdl-base" type="url" class="form-control font-mono @error('base_url') is-invalid @enderror" wire:model.blur="base_url" maxlength="255">
                                @error('base_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('Without /v2.0. The default is the host in PDL\'s guide; PDL tells you which host to use for staging and production.') }}</div>
                                @if ($insecure)
                                    <x-alert variant="warning" class="mt-2 mb-0">{{ __('This address uses http://, so the password and files travel unencrypted. Ask PDL for an https:// address.') }}</x-alert>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="pdl-email">{{ __('Account email') }}</label>
                                <input id="pdl-email" type="email" class="form-control @error('email') is-invalid @enderror" wire:model="email" maxlength="255" autocomplete="off">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="pdl-password">{{ __('Password') }}</label>
                                <input id="pdl-password" type="password" class="form-control @error('password') is-invalid @enderror" wire:model="password" maxlength="255" autocomplete="new-password"
                                       placeholder="{{ $hasPassword ? __('Saved. Leave empty to keep it') : '' }}">
                                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('Stored encrypted and never shown again.') }}
                                    @if ($hasPassword)
                                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-danger" wire:click="forgetPassword" wire:confirm="{{ __('Remove the stored password? PDL distribution will be disabled.') }}">{{ __('Remove password') }}</button>
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="pdl-label-id">{{ __('PDL label id') }}</label>
                                <input id="pdl-label-id" class="form-control font-mono @error('label_id') is-invalid @enderror" wire:model="label_id" maxlength="24" placeholder="61b990392d56b657a5c186d7">
                                @error('label_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('The label (or sub-label) to deliver as. PDL gives you this id; it is checked when PDL verifies each upload.') }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="pdl-label-name">{{ __('PDL label name') }}</label>
                                <input id="pdl-label-name" class="form-control @error('label_name') is-invalid @enderror" wire:model="label_name" maxlength="150">
                                @error('label_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('Exactly as in COSMOS. Leave empty to send each release\'s own label name.') }}</div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" size="16" /> {{ __('Save') }}</button>
                            <button type="button" class="btn btn-light" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                                <span wire:loading.remove wire:target="testConnection"><x-icon name="plug" size="16" /></span>
                                <span wire:loading wire:target="testConnection" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                {{ __('Test connection') }}
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Genres --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="music-2" size="18" />
                        <h2 class="card-title">{{ __('Genres') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">{{ __('PDL accepts only its approved genre and sub-genre pairs. Releases with an unmapped genre cannot be sent.') }}</p>
                        @forelse ($genres as $genre)
                            <div class="row g-2 align-items-center mb-2" wire:key="genre-{{ $genre->id }}">
                                <label class="col-sm-4 col-form-label" for="genre-{{ $genre->id }}">{{ $genre->name }}</label>
                                <div class="col-sm-8">
                                    <select id="genre-{{ $genre->id }}" class="form-select @error('genre_map.'.$genre->id) is-invalid @enderror" wire:model="genre_map.{{ $genre->id }}">
                                        <option value="">{{ __('Not mapped') }}</option>
                                        @foreach ($cosmosGenres as $g => $subs)
                                            <optgroup label="{{ $g }}">
                                                @foreach ($subs as $sub)
                                                    <option value="{{ $g }}|{{ $sub }}">{{ $g }} › {{ $sub }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @empty
                            <p class="mb-0 text-muted">{{ __('No genres yet.') }}</p>
                        @endforelse
                    </div>
                </div>

                {{-- Languages --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="globe" size="18" />
                        <h2 class="card-title">{{ __('Languages') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">{{ __('Languages used in your catalog. Languages with the same name in PDL\'s list are matched automatically; "No linguistic content" (zxx) is sent as instrumental.') }}</p>
                        @forelse ($languages as $language)
                            @php($automatic = $auto($language))
                            <div class="row g-2 align-items-center mb-2" wire:key="lang-{{ $language->code }}">
                                <label class="col-sm-4 col-form-label" for="lang-{{ $language->code }}">{{ $language->name }} <span class="text-muted font-mono small">{{ $language->code }}</span></label>
                                <div class="col-sm-8">
                                    <select id="lang-{{ $language->code }}" class="form-select @error('language_map.'.$language->code) is-invalid @enderror" wire:model="language_map.{{ $language->code }}">
                                        <option value="">{{ $automatic ? __('Automatic: :language', ['language' => $automatic]) : __('Not mapped') }}</option>
                                        @foreach (\LabelDesk\Addons\PdlDistribution\Services\Cosmos::LANGUAGES as $l)
                                            <option value="{{ $l }}">{{ $l }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @empty
                            <p class="mb-0 text-muted">{{ __('No releases or tracks have a language yet.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                {{-- Defaults --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="settings" size="18" />
                        <h2 class="card-title">{{ __('Release defaults') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="pdl-platforms">{{ __('Release on') }}</label>
                            <select id="pdl-platforms" class="form-select" wire:model="platforms">
                                @foreach (\LabelDesk\Addons\PdlDistribution\Services\Cosmos::PLATFORMS as $key => $label)
                                    <option value="{{ $key }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="pdl-any-date" wire:model="allow_any_go_live_date">
                            <label class="form-check-label" for="pdl-any-date">{{ __('Allow any go-live date') }}</label>
                            <div class="form-text">{{ __('Sent as allow_any_go_live_date when submitting.') }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pdl-mood">{{ __('Fallback mood') }}</label>
                            <select id="pdl-mood" class="form-select" wire:model="default_mood">
                                <option value="">{{ __('Choose when sending') }}</option>
                                @foreach (\LabelDesk\Addons\PdlDistribution\Services\Cosmos::MOODS as $mood)
                                    <option value="{{ $mood }}">{{ \App\Models\Mood::PDL[$mood] ?? $mood }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Only for tracks without a mood; each track\'s own mood is sent first.') }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pdl-album-type">{{ __('Album type') }}</label>
                            <select id="pdl-album-type" class="form-select @error('album_type') is-invalid @enderror" wire:model="album_type">
                                <option value="Album">{{ __('Album (non-film)') }}</option>
                                <option value="Film">{{ __('Film soundtrack') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pdl-category">{{ __('Album category') }}</label>
                            <input id="pdl-category" class="form-control @error('album_category') is-invalid @enderror" wire:model="album_category" maxlength="60">
                            @error('album_category') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">{{ __('Used when a release has no version (the version is sent as the category, e.g. "Remix version").') }}</div>
                        </div>
                        <fieldset class="mb-3">
                            <legend class="form-label">{{ __('Content type per release type') }}</legend>
                            @foreach (\App\Enums\ReleaseType::cases() as $type)
                                <div class="row g-2 align-items-center mb-1">
                                    <label class="col-5 col-form-label col-form-label-sm" for="ct-{{ $type->value }}">{{ $type->label() }}</label>
                                    <div class="col-7">
                                        <select id="ct-{{ $type->value }}" class="form-select form-select-sm" wire:model="content_types.{{ $type->value }}">
                                            @foreach (\LabelDesk\Addons\PdlDistribution\Services\Cosmos::CONTENT_TYPES as $ct)
                                                <option value="{{ $ct }}">{{ $ct }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </fieldset>
                        <div class="mb-3">
                            <label class="form-label" for="pdl-publisher">{{ __('Publisher') }}</label>
                            <input id="pdl-publisher" class="form-control" wire:model="publisher" maxlength="150">
                            <div class="form-text">{{ __('Used when no track has a publisher credit. Empty: the label name.') }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pdl-crbt">{{ __('Default CRBT start (seconds)') }}</label>
                            <input id="pdl-crbt" type="number" min="0" max="600" class="form-control @error('crbt_start') is-invalid @enderror" wire:model="crbt_start">
                            @error('crbt_start') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">{{ __('For tracks without their own CRBT start.') }}</div>
                        </div>
                        <div>
                            <label class="form-label" for="pdl-date-format">{{ __('Date format') }}</label>
                            <select id="pdl-date-format" class="form-select" wire:model="date_format">
                                @foreach (\LabelDesk\Addons\PdlDistribution\Services\PdlSettings::DATE_FORMATS as $key => $label)
                                    <option value="{{ $key }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('PDL\'s guide and its example requests disagree; confirm with PDL.') }}</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" size="16" /> {{ __('Save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <div class="card mb-3">
        <div class="card-header">
            <x-icon name="copyright" size="18" />
            <h2 class="card-title">{{ __('Defaults for new releases') }}</h2>
        </div>
        <form class="card-body" wire:submit="saveDefaults" novalidate>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="label_name">{{ __('Label name') }}</label>
                    <input id="label_name" class="form-control @error('label_name') is-invalid @enderror" wire:model.live.debounce.400ms="label_name" maxlength="150" placeholder="{{ config('app.name') }}">
                    @error('label_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">{{ __('Leave empty to use the application name.') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="default_language">{{ __('Default language') }}</label>
                    <select id="default_language" class="form-select @error('default_language') is-invalid @enderror" wire:model="default_language">
                        @foreach ($languages as $language)
                            <option value="{{ $language->code }}">{{ $language->name }}</option>
                        @endforeach
                    </select>
                    @error('default_language') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="c_line_format">{{ __('© line') }}</label>
                    <input id="c_line_format" class="form-control @error('c_line_format') is-invalid @enderror" wire:model.live.debounce.400ms="c_line_format" maxlength="200">
                    @error('c_line_format') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">{{ __('Preview:') }} <strong>© {{ $preview['c'] }}</strong></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="p_line_format">{{ __('℗ line') }}</label>
                    <input id="p_line_format" class="form-control @error('p_line_format') is-invalid @enderror" wire:model.live.debounce.400ms="p_line_format" maxlength="200">
                    @error('p_line_format') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">{{ __('Preview:') }} <strong>℗ {{ $preview['p'] }}</strong></div>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-3">{!! __('Use <code>{year}</code> for the current year and <code>{label}</code> for the label name. Existing releases are not changed.') !!}</p>
            <button type="submit" class="btn btn-primary">{{ __('Save defaults') }}</button>
        </form>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <x-icon name="upload" size="18" />
            <h2 class="card-title">{{ __('Bulk upload') }}</h2>
            @if (Route::has('bulk-upload.index'))
                <a href="{{ route('bulk-upload.index') }}" class="small ms-auto">{{ __('Open bulk upload') }} &rarr;</a>
            @endif
        </div>
        <form class="card-body" wire:submit="saveBulk" novalidate>
            <p class="small text-muted">{{ __('Administrators can always bulk upload artists and releases. Choose who else may:') }}</p>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="bulk_staff" wire:model="bulk_staff">
                <label class="form-check-label" for="bulk_staff">
                    {{ __('Label team') }}
                    <span class="d-block small text-muted">{{ __('Staff whose role includes "Bulk upload artists and releases" (Label Manager, Artist Manager by default).') }}</span>
                </label>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="bulk_artists" wire:model="bulk_artists">
                <label class="form-check-label" for="bulk_artists">
                    {{ __('Artist accounts') }}
                    <span class="d-block small text-muted">{{ __('Artists can bulk upload releases for their own artist profiles only. Releases arrive as drafts for your review.') }}</span>
                </label>
            </div>
            <button type="submit" class="btn btn-outline-primary">{{ __('Save') }}</button>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <x-icon name="tags" size="18" />
                    <h2 class="card-title">{{ __('Genres') }}</h2>
                    <span class="small text-muted ms-auto">{{ trans_choice(':count genre|:count genres', $genres->count()) }}</span>
                </div>
                <form class="card-body border-bottom d-flex gap-2" wire:submit="addGenre" novalidate>
                    <div class="flex-grow-1">
                        <input class="form-control @error('newGenre') is-invalid @enderror" wire:model="newGenre" placeholder="{{ __('New genre') }}" maxlength="80" aria-label="{{ __('New genre') }}">
                        @error('newGenre') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <button type="submit" class="btn btn-outline-primary align-self-start"><x-icon name="plus" size="16" /> {{ __('Add') }}</button>
                </form>
                <ul class="ld-list" style="max-height: 420px; overflow-y: auto">
                    @foreach ($genres as $genre)
                        <li class="ld-list__item" wire:key="genre-{{ $genre->id }}">
                            @if ($editingGenre === $genre->id)
                                <form class="d-flex gap-2 flex-grow-1" wire:submit="renameGenre" novalidate>
                                    <div class="flex-grow-1">
                                        <input class="form-control form-control-sm @error('editingGenreName') is-invalid @enderror" wire:model="editingGenreName" maxlength="80" aria-label="{{ __('Genre name') }}">
                                        @error('editingGenreName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary align-self-start">{{ __('Save') }}</button>
                                    <button type="button" class="btn btn-sm btn-light align-self-start" wire:click="$set('editingGenre', null)">{{ __('Cancel') }}</button>
                                </form>
                            @else
                                <span class="flex-grow-1 min-w-0">
                                    <span @class(['d-block fw-semibold small', 'text-subtle' => ! $genre->is_active])>{{ $genre->name }}</span>
                                    <span class="ld-list__meta">{{ trans_choice(':count release|:count releases', $genre->releases_count) }}@unless ($genre->is_active) &middot; {{ __('Hidden') }}@endunless</span>
                                </span>
                                <button type="button" class="btn btn-sm btn-light" wire:click="editGenre({{ $genre->id }})" title="{{ __('Rename') }}"><x-icon name="pencil" size="14" /><span class="visually-hidden">{{ __('Rename') }}</span></button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="toggleGenre({{ $genre->id }})" title="{{ $genre->is_active ? __('Hide from new releases') : __('Show again') }}">
                                    <x-icon :name="$genre->is_active ? 'eye-off' : 'eye'" size="14" /><span class="visually-hidden">{{ $genre->is_active ? __('Hide') : __('Show') }}</span>
                                </button>
                                @if ($genre->releases_count === 0)
                                    <button type="button" class="btn btn-sm btn-light text-danger" wire:click="deleteGenre({{ $genre->id }})" wire:confirm="{{ __('Delete the genre ":name"?', ['name' => $genre->name]) }}" title="{{ __('Delete') }}">
                                        <x-icon name="trash-2" size="14" /><span class="visually-hidden">{{ __('Delete') }}</span>
                                    </button>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <x-icon name="languages" size="18" />
                    <h2 class="card-title">{{ __('Languages') }}</h2>
                    <span class="small text-muted ms-auto">{{ trans_choice(':count language|:count languages', $languages->count()) }}</span>
                </div>
                <form class="card-body border-bottom row g-2" wire:submit="addLanguage" novalidate>
                    <div class="col-4 col-sm-3">
                        <input class="form-control text-lowercase @error('newLanguageCode') is-invalid @enderror" wire:model="newLanguageCode" placeholder="{{ __('Code') }}" maxlength="3" aria-label="{{ __('ISO code') }}">
                    </div>
                    <div class="col">
                        <input class="form-control @error('newLanguageName') is-invalid @enderror" wire:model="newLanguageName" placeholder="{{ __('Language name') }}" maxlength="80" aria-label="{{ __('Language name') }}">
                    </div>
                    <div class="col-auto"><button type="submit" class="btn btn-outline-primary"><x-icon name="plus" size="16" /> {{ __('Add') }}</button></div>
                    @error('newLanguageCode') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                    @error('newLanguageName') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                </form>
                <ul class="ld-list" style="max-height: 420px; overflow-y: auto">
                    @foreach ($languages as $language)
                        <li class="ld-list__item" wire:key="language-{{ $language->id }}">
                            <span class="ld-list__icon font-monospace small">{{ $language->code }}</span>
                            <span class="flex-grow-1 small fw-semibold">{{ $language->name }}</span>
                            @if ($language->code === $default_language)
                                <x-badge variant="info">{{ __('Default') }}</x-badge>
                            @else
                                <button type="button" class="btn btn-sm btn-light text-danger" wire:click="deleteLanguage({{ $language->id }})" wire:confirm="{{ __('Delete the language ":name"?', ['name' => $language->name]) }}" title="{{ __('Delete') }}">
                                    <x-icon name="trash-2" size="14" /><span class="visually-hidden">{{ __('Delete') }}</span>
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

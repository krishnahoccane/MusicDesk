<div>
    <x-page-header :title="$editing ? __('Edit release') : __('New release')"
                   :subtitle="$editing ? $release->displayTitle() : __('Start with the essentials; you can complete the details before submitting.')" />

    @error('form')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="disc-3" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Release') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="title" class="form-label">{{ __('Title') }}</label>
                                <input id="title" type="text" wire:model="title" maxlength="200" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('title')])>
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="type" class="form-label">{{ __('Type') }}</label>
                                <select id="type" wire:model="type" class="form-select">
                                    @foreach ($types as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label for="version" class="form-label">{{ __('Version') }} <span class="text-muted fw-normal">({{ __('optional') }})</span></label>
                                <input id="version" type="text" wire:model="version" maxlength="100" placeholder="{{ __('e.g. Deluxe Edition, Remixes') }}"
                                       @class(['form-control', 'is-invalid' => $errors->has('version')])>
                                @error('version') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="mic-vocal" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Artists') }}</h2>
                    </div>
                    <div class="card-body">
                        @include('livewire.catalog.partials.artist-credits')
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="calendar" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Release details') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6 col-md-3">
                                <label for="release_date" class="form-label">{{ __('Release date') }}</label>
                                <input id="release_date" type="date" wire:model="release_date" @class(['form-control', 'is-invalid' => $errors->has('release_date')])>
                                @error('release_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label for="original_release_date" class="form-label">{{ __('Original release') }}</label>
                                <input id="original_release_date" type="date" wire:model="original_release_date" @class(['form-control', 'is-invalid' => $errors->has('original_release_date')])>
                                @error('original_release_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label for="genre_id" class="form-label">{{ __('Genre') }}</label>
                                <select id="genre_id" wire:model="genre_id" @class(['form-select', 'is-invalid' => $errors->has('genre_id')])>
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($genres as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label for="language_id" class="form-label">{{ __('Language') }}</label>
                                <select id="language_id" wire:model="language_id" @class(['form-select', 'is-invalid' => $errors->has('language_id')])>
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($languages as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="upc" class="form-label">{{ __('UPC / EAN') }}</label>
                                <input id="upc" type="text" inputmode="numeric" wire:model="upc" maxlength="20" autocomplete="off"
                                       @class(['form-control font-monospace', 'is-invalid' => $errors->has('upc')])>
                                <div class="form-text">{{ __('Leave empty if your distributor assigns one.') }}</div>
                                @error('upc') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-sm-6">
                                <label for="catalog_number" class="form-label">{{ __('Catalogue number') }}</label>
                                <input id="catalog_number" type="text" wire:model="catalog_number" maxlength="50" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('catalog_number')])>
                                @error('catalog_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @php($labelOptions = ! auth()->user()->can('labels.view') ? collect() : \App\Models\Label::query()->where(fn ($q) => $q->where('is_active', true)->when($label_id !== '', fn ($q2) => $q2->orWhere('id', (int) $label_id)))->with('parent')->orderBy('name')->get())
                            @if ($labelOptions->isNotEmpty())
                                <div class="col-md-4">
                                    <label for="label_id" class="form-label">{{ __('Label') }}</label>
                                    <select id="label_id" wire:model.live="label_id" @class(['form-select', 'is-invalid' => $errors->has('label_id')])>
                                        <option value="">{{ \App\Support\CatalogDefaults::labelName() ?: __('Main label') }} ({{ __('main label') }})</option>
                                        @foreach ($labelOptions as $option)
                                            <option value="{{ $option->id }}">{{ $option->path() }}</option>
                                        @endforeach
                                    </select>
                                    @error('label_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif
                            @if ($label_id === '')
                                <div class="col-md-4">
                                    <label for="label_name" class="form-label">{{ $labelOptions->isNotEmpty() ? __('Label name (as delivered)') : __('Label') }}</label>
                                    <input id="label_name" type="text" wire:model="label_name" maxlength="150" class="form-control">
                                </div>
                            @endif
                            <div class="col-md-4">
                                <label for="c_line" class="form-label">{{ __('© line') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text">©</span>
                                    <input id="c_line" type="text" wire:model="c_line" maxlength="200" @class(['form-control', 'is-invalid' => $errors->has('c_line')])>
                                </div>
                                <div class="form-text">{{ __('Artwork and packaging copyright.') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label for="p_line" class="form-label">{{ __('℗ line') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text">℗</span>
                                    <input id="p_line" type="text" wire:model="p_line" maxlength="200" @class(['form-control', 'is-invalid' => $errors->has('p_line')])>
                                </div>
                                <div class="form-text">{{ __('Sound recording copyright.') }}</div>
                            </div>
                            <div class="col-12">
                                <label for="notes" class="form-label">{{ __('Internal notes') }}</label>
                                <textarea id="notes" wire:model="notes" rows="3" maxlength="5000" @class(['form-control', 'is-invalid' => $errors->has('notes')])></textarea>
                                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">
                        <x-icon name="image" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Artwork') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 text-center">
                            @if ($artwork && ! $errors->has('artwork'))
                                <img src="{{ $artwork->temporaryUrl() }}" alt="" class="ld-artwork" style="width: 220px; height: 220px">
                            @elseif ($editing && $release->artwork_path && ! $remove_artwork)
                                <x-release-artwork :release="$release" size="220" />
                            @else
                                <x-release-artwork :release="new App\Models\Release" size="220" />
                            @endif
                        </div>
                        <input type="file" id="artwork" wire:model="artwork" accept="image/jpeg,image/png"
                               @class(['form-control', 'is-invalid' => $errors->has('artwork')]) aria-label="{{ __('Choose artwork') }}">
                        <div class="form-text">{{ __('Square JPG or PNG, at least :px × :px px, up to :mb MB.', ['px' => $minPx, 'mb' => $maxMb]) }}</div>
                        <div wire:loading wire:target="artwork" class="small text-muted">{{ __('Uploading…') }}</div>
                        @error('artwork') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @if ($editing && $release->artwork_path && ! $artwork)
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="remove_artwork" wire:model.live="remove_artwork">
                                <label class="form-check-label" for="remove_artwork">{{ __('Remove the current artwork') }}</label>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="{{ $editing ? route('releases.show', $release) : route('releases.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,artwork">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ $editing ? __('Save changes') : __('Create release') }}
            </button>
        </div>
    </form>
</div>

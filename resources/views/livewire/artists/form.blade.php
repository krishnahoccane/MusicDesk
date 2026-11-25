<div>
    <x-page-header :title="$editing ? __('Edit artist') : __('Add artist')"
                   :subtitle="$editing ? $artist->name : __('Create a profile for a new artist on your roster.')" />

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="mic-vocal" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Profile') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">{{ __('Artist name') }}</label>
                                <input id="name" type="text" wire:model="name" maxlength="150" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('name')]) required>
                                <div class="form-text">{{ __('As credited on releases.') }}</div>
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="legal_name" class="form-label">{{ __('Legal name') }} <span class="text-muted fw-normal">({{ __('optional') }})</span></label>
                                <input id="legal_name" type="text" wire:model="legal_name" maxlength="200" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('legal_name')])>
                                <div class="form-text">{{ __('Used on contracts and statements.') }}</div>
                                @error('legal_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="type" class="form-label">{{ __('Type') }}</label>
                                <select id="type" wire:model="type" @class(['form-select', 'is-invalid' => $errors->has('type')])>
                                    @foreach ($types as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="status" class="form-label">{{ __('Status') }}</label>
                                <select id="status" wire:model="status" @class(['form-select', 'is-invalid' => $errors->has('status')])>
                                    @foreach ($statuses as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="primary_genre" class="form-label">{{ __('Main genre') }}</label>
                                <input id="primary_genre" type="text" wire:model="primary_genre" maxlength="80" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('primary_genre')])>
                                @error('primary_genre') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="country" class="form-label">{{ __('Country') }}</label>
                                <select id="country" wire:model="country" @class(['form-select', 'is-invalid' => $errors->has('country')])>
                                    <option value="">{{ __('Not specified') }}</option>
                                    @foreach ($countries as $code => $countryName)
                                        <option value="{{ $code }}">{{ $countryName }}</option>
                                    @endforeach
                                </select>
                                @error('country') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="email" class="form-label">{{ __('Email') }}</label>
                                <input id="email" type="email" wire:model="email" maxlength="255" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('email')])>
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="phone" class="form-label">{{ __('Phone') }}</label>
                                <input id="phone" type="tel" wire:model="phone" maxlength="40" autocomplete="off"
                                       @class(['form-control', 'is-invalid' => $errors->has('phone')])>
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label for="bio" class="form-label">{{ __('Biography') }}</label>
                                <textarea id="bio" wire:model="bio" rows="5" maxlength="5000"
                                          @class(['form-control', 'is-invalid' => $errors->has('bio')])></textarea>
                                @error('bio') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label for="notes" class="form-label">{{ __('Internal notes') }}</label>
                                <textarea id="notes" wire:model="notes" rows="3" maxlength="5000"
                                          @class(['form-control', 'is-invalid' => $errors->has('notes')])></textarea>
                                <div class="form-text">{{ __('Visible to your team only.') }}</div>
                                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">
                        <x-icon name="camera" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Photo') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            @if ($photo && ! $errors->has('photo'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="" class="ld-artist-avatar" style="width: 96px; height: 96px">
                            @elseif ($editing && $artist->photo_path && ! $remove_photo)
                                <x-artist-avatar :artist="$artist" size="96" />
                            @else
                                <span class="ld-artist-avatar ld-artist-avatar--initials" style="width: 96px; height: 96px; font-size: 32px" aria-hidden="true">
                                    <x-icon name="image" size="32" />
                                </span>
                            @endif
                            <div class="small text-muted">{{ __('JPG, PNG or WebP, up to :mb MB. Resized and cleaned of location data automatically.', ['mb' => $imageMaxMb]) }}</div>
                        </div>
                        <input type="file" id="photo" wire:model="photo" accept="image/jpeg,image/png,image/webp"
                               @class(['form-control', 'is-invalid' => $errors->has('photo')]) aria-label="{{ __('Choose a photo') }}">
                        <x-upload-progress for="photo" />
                        @error('photo') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @if ($editing && $artist->photo_path && ! $photo)
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="remove_photo" wire:model.live="remove_photo">
                                <label class="form-check-label" for="remove_photo">{{ __('Remove the current photo') }}</label>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="{{ $editing ? route('artists.show', $artist) : route('artists.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,photo">
                <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                {{ $editing ? __('Save changes') : __('Add artist') }}
            </button>
        </div>
    </form>
</div>

{{-- Needs: $artistRows (component), $artistOptions, $artistRoles, optional $disabled --}}
@php($disabled = $disabled ?? false)
<div>
    @if ($showNewArtist && ! $disabled)
        @can('create', App\Models\Artist::class)
            <div class="ld-inline-form border rounded-3 p-3 mb-3" wire:key="new-artist-form">
                <div class="row g-2 align-items-end">
                    <div class="col-sm">
                        <label for="new-artist-name" class="form-label small">{{ __('Artist name') }}</label>
                        <input id="new-artist-name" type="text" wire:model="newArtistName" maxlength="150" autocomplete="off"
                               wire:keydown.enter.prevent="createArtist" x-init="$el.focus()"
                               @class(['form-control', 'is-invalid' => $errors->has('newArtistName')])>
                        @error('newArtistName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-4">
                        <label for="new-artist-type" class="form-label small">{{ __('Type') }}</label>
                        <select id="new-artist-type" wire:model="newArtistType" class="form-select">
                            @foreach (App\Enums\ArtistType::cases() as $artistType)
                                <option value="{{ $artistType->value }}">{{ $artistType->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-1">
                        <button type="button" class="btn btn-primary" wire:click="createArtist" wire:loading.attr="disabled" wire:target="createArtist">{{ __('Add') }}</button>
                        <button type="button" class="btn btn-light" wire:click="$set('showNewArtist', false)">{{ __('Cancel') }}</button>
                    </div>
                </div>
                <div class="form-text">{{ __('Added to your roster as an active artist. Complete the profile later under Artists.') }}</div>
            </div>
        @endcan
    @endif

    @error('artistRows') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
    @forelse ($artistRows as $i => $row)
        <div class="row g-2 align-items-start mb-2" wire:key="artist-row-{{ $i }}">
            <div class="col-sm">
                <label class="visually-hidden" for="artist-{{ $i }}">{{ __('Artist') }}</label>
                <select id="artist-{{ $i }}" wire:model="artistRows.{{ $i }}.artist_id" @disabled($disabled)
                        @class(['form-select', 'is-invalid' => $errors->has("artistRows.$i.artist_id")])>
                    <option value="">{{ __('Choose an artist…') }}</option>
                    @foreach ($artistOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @error("artistRows.$i.artist_id") <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-sm-4">
                <label class="visually-hidden" for="artist-role-{{ $i }}">{{ __('Role') }}</label>
                <select id="artist-role-{{ $i }}" wire:model="artistRows.{{ $i }}.role" class="form-select" @disabled($disabled)>
                    @foreach ($artistRoles as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @unless ($disabled)
                <div class="col-auto d-flex gap-1">
                    <button type="button" class="btn btn-light btn-sm" wire:click="moveArtistRow({{ $i }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="14" /></button>
                    <button type="button" class="btn btn-light btn-sm" wire:click="moveArtistRow({{ $i }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="14" /></button>
                    <button type="button" class="btn btn-light btn-sm text-danger" wire:click="removeArtistRow({{ $i }})" aria-label="{{ __('Remove artist') }}"><x-icon name="x" size="14" /></button>
                </div>
            @endunless
        </div>
    @empty
        <p class="small text-muted mb-2">{{ __('No artists yet.') }}</p>
    @endforelse
    @unless ($disabled)
        <button type="button" class="btn btn-sm btn-light" wire:click="addArtistRow"><x-icon name="plus" size="14" /> {{ __('Add artist') }}</button>
    @endunless
</div>

{{-- Needs: $artistRows (component), $artistOptions, $artistRoles, optional $disabled --}}
@php($disabled = $disabled ?? false)
<div>
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
        @if ($artistOptions->isEmpty())
            <span class="small text-muted ms-2">{{ __('Add artists to your roster first.') }} @can('create', App\Models\Artist::class)<a href="{{ route('artists.create') }}">{{ __('Add artist') }}</a>@endcan</span>
        @endif
    @endunless
</div>

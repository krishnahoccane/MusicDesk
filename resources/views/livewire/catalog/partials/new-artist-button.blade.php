{{-- Right side of the Artists card header (release and track forms). Needs: $showNewArtist, optional $disabled --}}
@if (! ($disabled ?? false))
    @can('create', App\Models\Artist::class)
        <button type="button" class="btn btn-sm btn-light ms-auto" wire:click="$toggle('showNewArtist')" aria-expanded="{{ $showNewArtist ? 'true' : 'false' }}">
            <x-icon name="user-plus" size="14" /> {{ __('New artist') }}
        </button>
    @endcan
@endif

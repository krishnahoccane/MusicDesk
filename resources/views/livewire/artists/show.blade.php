<div>
    {{-- Header --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <x-artist-avatar :artist="$artist" size="72" />
            <div class="flex-grow-1 min-w-0">
                <h1 class="ld-page-header__title mb-1 text-break">{{ $artist->name }}</h1>
                <div class="d-flex flex-wrap align-items-center gap-2 small text-muted">
                    @if ($artist->trashed())
                        <x-badge variant="danger">{{ __('Deleted') }}</x-badge>
                    @else
                        <x-badge :variant="$artist->status->variant()">{{ $artist->status->label() }}</x-badge>
                    @endif
                    <span>{{ $artist->type->label() }}</span>
                    @if ($artist->primary_genre) <span>&middot; {{ $artist->primary_genre }}</span> @endif
                    @if ($artist->country_name) <span>&middot; {{ $artist->country_name }}</span> @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($artist->trashed())
                    @can('restore', $artist)
                        <button type="button" class="btn btn-primary" wire:click="restore"><x-icon name="rotate-ccw" size="16" /> {{ __('Restore') }}</button>
                    @endcan
                @else
                    @can('update', $artist)
                        <a href="{{ route('artists.edit', $artist) }}" class="btn btn-light"><x-icon name="pencil" size="16" /> {{ __('Edit') }}</a>
                    @endcan
                    @can('delete', $artist)
                        <button type="button" class="btn btn-light text-danger" wire:click="delete"
                                wire:confirm="{{ __('Delete :name? Their contracts and documents are kept and the artist can be restored.', ['name' => $artist->name]) }}"
                                aria-label="{{ __('Delete artist') }}"><x-icon name="trash-2" size="16" /></button>
                    @endcan
                @endif
            </div>
        </div>
    </div>

    @if ($artist->trashed())
        <x-alert variant="warning" class="mb-4">{{ __('This artist has been deleted. Restore it to make changes.') }}</x-alert>
    @endif

    {{-- Tabs --}}
    @php($labels = [
        'overview' => [__('Overview'), 'layout-dashboard'],
        'releases' => [__('Releases'), 'disc-3'],
        'contacts' => [__('Contacts'), 'contact'],
        'links' => [__('Links'), 'link'],
        'contracts' => [__('Contracts'), 'file-signature'],
        'documents' => [__('Documents'), 'paperclip'],
        'earnings' => [__('Earnings'), 'wallet'],
        'activity' => [__('Activity'), 'history'],
    ])
    <ul class="nav nav-underline mb-4 ld-settings-tabs" role="tablist">
        @foreach ($tabs as $key)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="show('{{ $key }}')"
                        @class(['nav-link d-flex align-items-center gap-2', 'active' => $tab === $key])
                        aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    <x-icon :name="$labels[$key][1]" size="16" /> {{ $labels[$key][0] }}
                    @if (isset($counts[$key]) && $counts[$key] > 0)
                        <span class="ld-badge py-0 px-2">{{ $counts[$key] }}</span>
                    @endif
                </button>
            </li>
        @endforeach
    </ul>

    <div wire:loading.delay.class="opacity-50" wire:target="show">
        @switch($tab)
            @case('contacts')
                <livewire:artists.contacts :artist="$artist" :key="'contacts-'.$artist->id" />
                @break
            @case('links')
                <livewire:artists.links :artist="$artist" :key="'links-'.$artist->id" />
                @break
            @case('contracts')
                <livewire:artists.contracts :artist="$artist" :key="'contracts-'.$artist->id" />
                @break
            @case('documents')
                <livewire:artists.documents :artist="$artist" :key="'documents-'.$artist->id" />
                @break
            @case('releases')
                @include('livewire.artists.partials.releases')
                @break
            @case('earnings')
                <livewire:artists.earnings :artist="$artist" :key="'earnings-'.$artist->id" />
                @break
            @case('activity')
                @include('livewire.artists.partials.activity')
                @break
            @default
                @include('livewire.artists.partials.overview')
        @endswitch
    </div>
</div>

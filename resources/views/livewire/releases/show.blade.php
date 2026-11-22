<div>
    {{-- Header --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <x-release-artwork :release="$release" size="88" />
            <div class="flex-grow-1 min-w-0">
                <h1 class="ld-page-header__title mb-1 text-break">{{ $release->displayTitle() }}</h1>
                <div class="text-muted mb-2">{{ $release->artistLine() ?: __('No artist yet') }}</div>
                <div class="d-flex flex-wrap align-items-center gap-2 small text-muted">
                    @if ($release->trashed())
                        <x-badge variant="danger">{{ __('Deleted') }}</x-badge>
                    @else
                        <x-badge :variant="$release->status->variant()">{{ $release->status->label() }}</x-badge>
                    @endif
                    <span>{{ $release->type->label() }}</span>
                    <span>&middot; {{ trans_choice('{0} no tracks|{1} 1 track|[2,*] :count tracks', $release->tracks_count, ['count' => $release->tracks_count]) }}</span>
                    @if ($release->release_date) <span>&middot; {{ App\Support\Format::date($release->release_date) }}</span> @endif
                    @if ($release->explicit) <x-badge>{{ __('Explicit') }}</x-badge> @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($release->trashed())
                    @can('restore', $release)
                        <button type="button" class="btn btn-primary" wire:click="restore"><x-icon name="rotate-ccw" size="16" /> {{ __('Restore') }}</button>
                    @endcan
                @else
                    @can('update', $release)
                        <a href="{{ route('releases.edit', $release) }}" class="btn btn-light"><x-icon name="pencil" size="16" /> {{ __('Edit') }}</a>
                    @endcan
                    @can('delete', $release)
                        <button type="button" class="btn btn-light text-danger" wire:click="delete"
                                wire:confirm="{{ __('Delete :title? It can be restored from the Deleted filter.', ['title' => $release->displayTitle()]) }}"
                                aria-label="{{ __('Delete release') }}"><x-icon name="trash-2" size="16" /></button>
                    @endcan
                @endif
            </div>
        </div>
    </div>

    @if (! $release->trashed() && ! $release->status->isEditable())
        <x-alert variant="info" class="mb-4">{{ __('This release is :status, so its details and tracks are locked.', ['status' => strtolower($release->status->label())]) }}
            @if (in_array('reopen', $actions, true))
                {{ __('Reopen it as a draft to make changes.') }}
            @elseif ($release->deliveries_count > 0 && in_array($release->status, [App\Enums\ReleaseStatus::Approved, App\Enums\ReleaseStatus::Ready], true))
                {{ __('It is in stores: take it down everywhere (or remove pending stores) before it can be reopened.') }}
            @endif
        </x-alert>
    @endif

    @php($tabLabels = ['overview' => [__('Overview'), 'layout-dashboard'], 'tracks' => [__('Tracks'), 'audio-lines'], 'distribution' => [__('Distribution'), 'send'], 'history' => [__('History'), 'history']])
    <ul class="nav nav-underline mb-4 ld-settings-tabs" role="tablist">
        @foreach ($tabs as $key)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="show('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link d-flex align-items-center gap-2', 'active' => $tab === $key])>
                    <x-icon :name="$tabLabels[$key][1]" size="16" /> {{ $tabLabels[$key][0] }}
                    @if ($key === 'tracks' && $release->tracks_count) <span class="ld-badge py-0 px-2">{{ $release->tracks_count }}</span> @endif
                    @if ($key === 'distribution' && $release->deliveries_count) <span class="ld-badge py-0 px-2">{{ $release->deliveries_count }}</span> @endif
                </button>
            </li>
        @endforeach
    </ul>

    @if ($tab === 'tracks')
        <livewire:releases.tracks :release="$release" :key="'tracks-'.$release->id" />
    @elseif ($tab === 'distribution')
        <livewire:releases.distribution :release="$release" :key="'distribution-'.$release->id" />
    @elseif ($tab === 'history')
        @include('livewire.releases.partials.history')
    @else
        @include('livewire.releases.partials.overview')
    @endif
</div>

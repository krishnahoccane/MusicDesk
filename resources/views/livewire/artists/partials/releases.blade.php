<div class="card">
    <div class="card-header">
        <x-icon name="disc-3" size="18" class="text-muted" />
        <h2 class="card-title">{{ __('Releases') }}</h2>
        @if (! $artist->trashed())
            @can('create', App\Models\Release::class)
                <a href="{{ route('releases.create', ['artist' => $artist->id]) }}" class="btn btn-sm btn-primary ms-auto"><x-icon name="plus" size="14" /> {{ __('New release') }}</a>
            @endcan
        @endif
    </div>
    @if ($releases->isEmpty())
        <x-empty-state icon="disc-3" :title="__('No releases yet')" :description="__('Releases crediting this artist appear here.')" />
    @else
        <ul class="list-unstyled mb-0">
            @foreach ($releases as $release)
                <li class="d-flex align-items-center gap-3 px-4 py-3 border-bottom" wire:key="ar-{{ $release->id }}">
                    <x-release-artwork :release="$release" size="44" />
                    <div class="flex-grow-1 min-w-0">
                        <a href="{{ route('releases.show', $release) }}" class="fw-semibold">{{ $release->displayTitle() }}</a>
                        <div class="small text-muted">
                            {{ $release->type->label() }} &middot; {{ App\Enums\ArtistRole::from($release->pivot->role)->label() }}
                            &middot; {{ trans_choice('{0} no tracks|{1} 1 track|[2,*] :count tracks', $release->tracks_count, ['count' => $release->tracks_count]) }}
                            @if ($release->release_date) &middot; {{ App\Support\Format::date($release->release_date) }} @endif
                        </div>
                    </div>
                    <x-badge :variant="$release->status->variant()">{{ $release->status->label() }}</x-badge>
                </li>
            @endforeach
        </ul>
    @endif
</div>

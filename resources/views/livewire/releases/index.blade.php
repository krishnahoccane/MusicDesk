<div>
    <x-page-header :title="__('Releases')" :subtitle="__('Singles, EPs and albums from draft to ready for delivery.')">
        @can('create', App\Models\Release::class)
            <x-slot:actions>
                <a href="{{ route('releases.create') }}" class="btn btn-primary"><x-icon name="plus" size="16" /> {{ __('New release') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    @if ($awaitingReview > 0 && ! in_array($status, ['submitted', 'in_review'], true))
        <x-alert variant="info" class="mb-4">
            {{ trans_choice('{1} 1 release is waiting for review.|[2,*] :count releases are waiting for review.', $awaitingReview, ['count' => $awaitingReview]) }}
            <button type="button" class="btn btn-link p-0 ms-1 fw-semibold align-baseline" wire:click="$set('status', 'submitted')">{{ __('Show them') }} &rarr;</button>
        </x-alert>
    @endif

    @if ($total === 0 && ! $filtered && $status !== 'deleted')
        <div class="card">
            <x-empty-state icon="disc-3" :title="__('No releases yet')" :description="__('Create your first release, add its tracks and artwork, then submit it for review.')">
                @can('create', App\Models\Release::class)
                    <a href="{{ route('releases.create') }}" class="btn btn-primary"><x-icon name="plus" size="16" /> {{ __('New release') }}</a>
                @endcan
            </x-empty-state>
        </div>
    @else
        <div class="card">
            <div class="card-body border-bottom py-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <div class="input-icon">
                            <x-icon name="search" size="16" />
                            <input type="search" class="form-control" placeholder="{{ __('Title, artist, UPC or catalogue no.') }}"
                                   wire:model.live.debounce.300ms="search" aria-label="{{ __('Search releases') }}" maxlength="100">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                            @can('releases.delete')
                                <option value="deleted">{{ __('Deleted') }}</option>
                            @endcan
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select" wire:model.live="type" aria-label="{{ __('Filter by type') }}">
                            <option value="">{{ __('All types') }}</option>
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select" wire:model.live="genre" aria-label="{{ __('Filter by genre') }}">
                            <option value="">{{ __('All genres') }}</option>
                            @foreach ($genres as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2 d-flex gap-2">
                        <select class="form-select" wire:model.live="sort" aria-label="{{ __('Sort') }}">
                            <option value="date">{{ __('Release date') }}</option>
                            <option value="newest">{{ __('Newest') }}</option>
                            <option value="title">{{ __('A–Z') }}</option>
                        </select>
                        @if ($filtered)
                            <button type="button" class="btn btn-light" wire:click="clearFilters" aria-label="{{ __('Clear filters') }}"><x-icon name="x" size="16" /></button>
                        @endif
                    </div>
                </div>
            </div>

            @if ($releases->isEmpty())
                <x-empty-state icon="search" :title="__('No releases found')" :description="__('Try a different search or filter.')" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Release') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('Type') }}</th>
                                <th class="d-none d-lg-table-cell">{{ __('Release date') }}</th>
                                <th class="d-none d-lg-table-cell text-end">{{ __('Tracks') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($releases as $release)
                                <tr wire:key="release-{{ $release->id }}">
                                    <td>
                                        <a href="{{ route('releases.show', $release) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                            <x-release-artwork :release="$release" size="44" />
                                            <span class="lh-sm min-w-0">
                                                <span class="fw-semibold d-block text-truncate">{{ $release->displayTitle() }}</span>
                                                <span class="small text-muted">{{ $release->artistLine() ?: '—' }}</span>
                                            </span>
                                        </a>
                                    </td>
                                    <td class="d-none d-md-table-cell">{{ $release->type->label() }}</td>
                                    <td class="d-none d-lg-table-cell">{{ App\Support\Format::date($release->release_date) }}</td>
                                    <td class="d-none d-lg-table-cell text-end">{{ $release->tracks_count }}</td>
                                    <td>
                                        @if ($release->trashed())
                                            <x-badge variant="danger">{{ __('Deleted') }}</x-badge>
                                        @else
                                            <x-badge :variant="$release->status->variant()">{{ $release->status->label() }}</x-badge>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if ($release->trashed())
                                            @can('restore', $release)
                                                <button type="button" class="btn btn-sm btn-light" wire:click="restore({{ $release->id }})"><x-icon name="rotate-ccw" size="14" /> {{ __('Restore') }}</button>
                                            @endcan
                                        @else
                                            <a href="{{ route('releases.show', $release) }}" class="btn btn-sm btn-light" aria-label="{{ __('Open :title', ['title' => $release->title]) }}"><x-icon name="chevron-right" size="14" /></a>
                                            @can('delete', $release)
                                                <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $release->id }})"
                                                        wire:confirm="{{ __('Delete :title? It can be restored from the Deleted filter.', ['title' => $release->displayTitle()]) }}"
                                                        aria-label="{{ __('Delete :title', ['title' => $release->title]) }}"><x-icon name="trash-2" size="14" /></button>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($releases->hasPages())
                    <div class="card-body border-top py-2">{{ $releases->links() }}</div>
                @endif
            @endif
        </div>
    @endif
</div>

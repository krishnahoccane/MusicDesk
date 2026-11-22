<div>
    <x-page-header :title="__('Artists')" :subtitle="__('Everyone on your roster, with their contacts, contracts and documents.')">
        @can('create', App\Models\Artist::class)
            <x-slot:actions>
                <a href="{{ route('artists.create') }}" class="btn btn-primary"><x-icon name="plus" size="16" /> {{ __('Add artist') }}</a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    @if ($total === 0 && ! $filtered && $status !== 'deleted')
        <div class="card">
            <x-empty-state icon="mic-vocal" :title="__('No artists yet')" :description="__('Add your first artist to start building your roster.')">
                @can('create', App\Models\Artist::class)
                    <a href="{{ route('artists.create') }}" class="btn btn-primary"><x-icon name="plus" size="16" /> {{ __('Add artist') }}</a>
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
                            <input type="search" class="form-control" placeholder="{{ __('Search name or email') }}"
                                   wire:model.live.debounce.300ms="search" aria-label="{{ __('Search artists') }}" maxlength="100">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by status') }}">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                            @can('artists.delete')
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
                        <select class="form-select" wire:model.live="country" aria-label="{{ __('Filter by country') }}">
                            <option value="">{{ __('All countries') }}</option>
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2 d-flex gap-2">
                        <select class="form-select" wire:model.live="sort" aria-label="{{ __('Sort') }}">
                            <option value="name">{{ __('A–Z') }}</option>
                            <option value="newest">{{ __('Newest') }}</option>
                        </select>
                        @if ($filtered)
                            <button type="button" class="btn btn-light" wire:click="clearFilters" aria-label="{{ __('Clear filters') }}"><x-icon name="x" size="16" /></button>
                        @endif
                    </div>
                </div>
            </div>

            @if ($artists->isEmpty())
                <x-empty-state icon="search" :title="__('No artists found')" :description="__('Try a different search or filter.')" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Artist') }}</th>
                                <th class="d-none d-md-table-cell">{{ __('Type') }}</th>
                                <th class="d-none d-lg-table-cell">{{ __('Country') }}</th>
                                <th class="d-none d-lg-table-cell">{{ __('Active contracts') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($artists as $artist)
                                <tr wire:key="artist-{{ $artist->id }}">
                                    <td>
                                        <a href="{{ route('artists.show', $artist) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                            <x-artist-avatar :artist="$artist" size="36" />
                                            <span class="lh-sm">
                                                <span class="fw-semibold d-block">{{ $artist->name }}</span>
                                                @if ($artist->primary_genre)
                                                    <span class="small text-muted">{{ $artist->primary_genre }}</span>
                                                @endif
                                            </span>
                                        </a>
                                    </td>
                                    <td class="d-none d-md-table-cell">{{ $artist->type->label() }}</td>
                                    <td class="d-none d-lg-table-cell">{{ $artist->country_name ?? '—' }}</td>
                                    <td class="d-none d-lg-table-cell">{{ $artist->active_contracts_count }}</td>
                                    <td>
                                        @if ($artist->trashed())
                                            <x-badge variant="danger">{{ __('Deleted') }}</x-badge>
                                        @else
                                            <x-badge :variant="$artist->status->variant()">{{ $artist->status->label() }}</x-badge>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if ($artist->trashed())
                                            @can('restore', $artist)
                                                <button type="button" class="btn btn-sm btn-light" wire:click="restore({{ $artist->id }})">
                                                    <x-icon name="rotate-ccw" size="14" /> {{ __('Restore') }}
                                                </button>
                                            @endcan
                                        @else
                                            <div class="dropdown">
                                                <button type="button" class="ld-icon-btn ms-auto" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Actions for :name', ['name' => $artist->name]) }}">
                                                    <x-icon name="ellipsis-vertical" size="18" />
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="{{ route('artists.show', $artist) }}"><x-icon name="eye" size="16" /> {{ __('View profile') }}</a></li>
                                                    @can('update', $artist)
                                                        <li><a class="dropdown-item" href="{{ route('artists.edit', $artist) }}"><x-icon name="pencil" size="16" /> {{ __('Edit') }}</a></li>
                                                    @endcan
                                                    @can('delete', $artist)
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><button type="button" class="dropdown-item text-danger" wire:click="delete({{ $artist->id }})"
                                                                    wire:confirm="{{ __('Delete :name? Their contracts and documents are kept and the artist can be restored.', ['name' => $artist->name]) }}">
                                                                <x-icon name="trash-2" size="16" /> {{ __('Delete') }}</button></li>
                                                    @endcan
                                                </ul>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($artists->hasPages())
                    <div class="card-body border-top py-2">{{ $artists->links() }}</div>
                @endif
            @endif
        </div>
    @endif
</div>

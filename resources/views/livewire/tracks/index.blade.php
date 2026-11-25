<div>
    <x-page-header :title="__('Tracks')" :subtitle="__('All recordings in your catalogue. Add tracks from a release.')" />

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-icon">
                        <x-icon name="search" size="16" />
                        <input type="search" class="form-control" placeholder="{{ __('Title, artist or ISRC') }}"
                               wire:model.live.debounce.300ms="search" aria-label="{{ __('Search tracks') }}" maxlength="100">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select" wire:model.live="status" aria-label="{{ __('Filter by release status') }}">
                        <option value="">{{ __('Any release status') }}</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <select class="form-select" wire:model.live="audio" aria-label="{{ __('Filter by audio') }}">
                        <option value="">{{ __('With or without audio') }}</option>
                        <option value="present">{{ __('Audio uploaded') }}</option>
                        <option value="missing">{{ __('Audio missing') }}</option>
                    </select>
                </div>
                <div class="col-md-1 text-md-end">
                    @if ($filtered)
                        <button type="button" class="btn btn-light" wire:click="clearFilters" aria-label="{{ __('Clear filters') }}"><x-icon name="x" size="16" /></button>
                    @endif
                </div>
            </div>
        </div>

        @if ($tracks->isEmpty())
            <x-empty-state icon="audio-lines" :title="$filtered ? __('No tracks found') : __('No tracks yet')"
                           :description="$filtered ? __('Try a different search or filter.') : __('Open a release and add its tracks.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Track') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('Release') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('ISRC') }}</th>
                            <th class="text-end">{{ __('Length') }}</th>
                            <th>{{ __('Audio') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tracks as $track)
                            <tr wire:key="t-{{ $track->id }}">
                                <td>
                                    <a href="{{ route('tracks.show', $track) }}" class="fw-semibold">{{ $track->displayTitle() }}</a>
                                    @if ($track->explicit) <x-badge class="ms-1">E</x-badge> @endif
                                        @if ($track->is_draft) <x-badge variant="warning" class="ms-1">{{ __('Draft') }}</x-badge> @endif
                                    <div class="small text-muted">{{ $track->artistLine() ?: '—' }}</div>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <a href="{{ route('releases.show', ['release' => $track->release, 'tab' => 'tracks']) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                        <x-release-artwork :release="$track->release" size="28" />
                                        <span class="small">{{ $track->release->displayTitle() }}</span>
                                    </a>
                                </td>
                                <td class="d-none d-lg-table-cell font-monospace small">{{ $track->formattedIsrc() ?? '—' }}</td>
                                <td class="text-end">{{ $track->formattedDuration() }}</td>
                                <td>
                                    @if ($track->hasAudio())
                                        <x-badge variant="success" icon="check">{{ __('Uploaded') }}</x-badge>
                                    @else
                                        <x-badge variant="warning">{{ __('Missing') }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($tracks->hasPages())
                <div class="card-body border-top py-2">{{ $tracks->links() }}</div>
            @endif
        @endif
    </div>
</div>

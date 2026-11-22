<div class="card">
    <div class="card-header">
        <x-icon name="audio-lines" size="18" class="text-muted" />
        <h2 class="card-title">{{ __('Tracks') }}</h2>
        @if ($tracks->isNotEmpty())
            <span class="small text-muted ms-2">{{ sprintf('%d:%02d', intdiv($totalDuration, 60), $totalDuration % 60) }}</span>
        @endif
        @if ($canAdd)
            <a href="{{ route('releases.tracks.create', $release) }}" class="btn btn-sm btn-primary ms-auto"><x-icon name="plus" size="14" /> {{ __('Add track') }}</a>
        @endif
    </div>

    @if ($tracks->isEmpty())
        <x-empty-state icon="audio-lines" :title="__('No tracks yet')" :description="__('Add each track with its master audio, ISRC and songwriter credits.')" />
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 3rem">#</th>
                        <th>{{ __('Track') }}</th>
                        <th class="d-none d-md-table-cell">{{ __('ISRC') }}</th>
                        <th class="d-none d-sm-table-cell text-end">{{ __('Length') }}</th>
                        <th>{{ __('Audio') }}</th>
                        <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                @foreach ($discs as $disc => $discTracks)
                    <tbody>
                        @if ($discs->count() > 1)
                            <tr><th colspan="6" class="small text-muted">{{ __('Disc :n', ['n' => $disc]) }}</th></tr>
                        @endif
                        @foreach ($discTracks as $track)
                            <tr wire:key="track-{{ $track->id }}">
                                <td class="text-muted">{{ $track->position }}</td>
                                <td>
                                    <div class="fw-semibold">
                                        {{ $track->displayTitle() }}
                                        @if ($track->explicit) <x-badge class="ms-1">E</x-badge> @endif
                                    </div>
                                    <div class="small text-muted">
                                        {{ $track->artistLine() ?: __('No artist') }}
                                        &middot; {{ trans_choice('{0} no credits|{1} 1 credit|[2,*] :count credits', $track->credits_count, ['count' => $track->credits_count]) }}
                                    </div>
                                    @if ($canPlay && $track->hasAudio())
                                        <audio controls preload="none" class="mt-2 w-100" style="max-width: 360px; height: 32px"
                                               src="{{ route('tracks.audio', $track) }}"></audio>
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell font-monospace small">{{ $track->formattedIsrc() ?? '—' }}</td>
                                <td class="d-none d-sm-table-cell text-end">{{ $track->formattedDuration() }}</td>
                                <td>
                                    @if ($track->hasAudio())
                                        <x-badge variant="success" icon="check">{{ strtoupper(str_replace('audio/', '', $track->audio_mime)) }}</x-badge>
                                        <div class="small text-muted">{{ number_format($track->audio_sample_rate / 1000, 1) }} kHz / {{ $track->audio_bit_depth }}-bit</div>
                                    @else
                                        <x-badge variant="warning">{{ __('Missing') }}</x-badge>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canEdit)
                                        <button type="button" class="btn btn-sm btn-light" wire:click="move({{ $track->id }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="14" /></button>
                                        <button type="button" class="btn btn-sm btn-light" wire:click="move({{ $track->id }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="14" /></button>
                                        <a href="{{ route('tracks.edit', $track) }}" class="btn btn-sm btn-light" aria-label="{{ __('Edit :title', ['title' => $track->title]) }}"><x-icon name="pencil" size="14" /></a>
                                    @else
                                        <a href="{{ route('tracks.show', $track) }}" class="btn btn-sm btn-light" aria-label="{{ __('View :title', ['title' => $track->title]) }}"><x-icon name="eye" size="14" /></a>
                                    @endif
                                    @if ($canDelete)
                                        <button type="button" class="btn btn-sm btn-light text-danger" wire:click="delete({{ $track->id }})"
                                                wire:confirm="{{ __('Delete ":title" and its audio file? This cannot be undone.', ['title' => $track->displayTitle()]) }}"
                                                aria-label="{{ __('Delete :title', ['title' => $track->title]) }}"><x-icon name="trash-2" size="14" /></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>

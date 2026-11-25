<div>
    <x-page-header :title="$editing ? $track->displayTitle() : __('Add track')"
                   :subtitle="__('On :release', ['release' => $release->displayTitle()])">
        @if ($editing && $track->hasAudio())
            @can('downloadMaster', $track)
                <x-slot:actions>
                    <a href="{{ route('tracks.download', $track) }}" class="btn btn-light"><x-icon name="download" size="16" /> {{ __('Download master') }}</a>
                </x-slot:actions>
            @endcan
        @endif
    </x-page-header>

    @if (! $canEdit)
        <x-alert variant="info" class="mb-4">
            {{ $release->isEditable() ? __('You can view this track but not change it.') : __('The release is :status, so its tracks are locked.', ['status' => strtolower($release->status->label())]) }}
        </x-alert>
    @endif

    @error('form')
        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
    @enderror

    @if ($editing && $track->is_draft)
        <x-alert variant="info" class="mb-4">{{ __('This track is a draft. Save it with “Save track” once it is complete; the release cannot be submitted while a track is a draft.') }}</x-alert>
    @endif

    <form wire:submit="save" novalidate>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="audio-lines" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Track') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="title" class="form-label">{{ __('Title') }}</label>
                                <input id="title" type="text" wire:model="title" maxlength="200" @disabled($disabled) @readonly($single)
                                       @class(['form-control', 'is-invalid' => $errors->has('title')])>
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if ($single)
                                    <div class="form-text">{{ __('A single’s track has the release title. Change it on the release.') }}</div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label for="version" class="form-label">{{ __('Version') }}</label>
                                <input id="version" type="text" wire:model="version" maxlength="100" placeholder="{{ __('e.g. Radio Edit') }}" @disabled($disabled)
                                       @class(['form-control', 'is-invalid' => $errors->has('version')])>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                @if ($editing)
                                    <span class="float-end small text-muted">{{ __('Track :n', ['n' => $trackNumber]) }}</span>
                                @endif
                                <label for="isrc" class="form-label">{{ __('ISRC') }}</label>
                                <input id="isrc" type="text" wire:model="isrc" maxlength="20" placeholder="CC-XXX-YY-NNNNN" autocomplete="off" @disabled($disabled)
                                       @class(['form-control font-monospace text-uppercase', 'is-invalid' => $errors->has('isrc')])>
                                @error('isrc') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="language_id" class="form-label">{{ __('Lyrics language') }}</label>
                                <select id="language_id" wire:model="language_id" class="form-select" @disabled($disabled)>
                                    <option value="">{{ __('Same as release') }}</option>
                                    @foreach ($languages as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="mood_id" class="form-label">{{ __('Mood') }}</label>
                                <select id="mood_id" wire:model="mood_id" @disabled($disabled)
                                        @class(['form-select', 'is-invalid' => $errors->has('mood_id')])>
                                    <option value="">{{ __('Choose a mood…') }}</option>
                                    @foreach ($moods as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('mood_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="explicit" wire:model="explicit" @disabled($disabled)>
                                    <label class="form-check-label" for="explicit">{{ __('Explicit lyrics') }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="mic-vocal" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Artists') }}</h2>
                        @include('livewire.catalog.partials.new-artist-button')
                    </div>
                    <div class="card-body">
                        @include('livewire.catalog.partials.artist-credits')
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <x-icon name="users-round" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Composer, lyricist and credits') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('Every track needs a composer and a lyricist (a songwriter counts as both). Shares apply to publishing roles and may not exceed 100% per role.') }}</p>
                        @error('creditRows') <x-alert variant="danger" class="mb-3">{{ $message }}</x-alert> @enderror
                        @foreach ($creditRows as $i => $row)
                            <div class="row g-2 align-items-start mb-2" wire:key="credit-{{ $i }}">
                                <div class="col-sm">
                                    <select wire:model="creditRows.{{ $i }}.contributor_id" aria-label="{{ __('Person') }}" @disabled($disabled)
                                            @class(['form-select', 'is-invalid' => $errors->has("creditRows.$i.contributor_id")])>
                                        <option value="">{{ __('Choose a person…') }}</option>
                                        @foreach ($contributors as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    @error("creditRows.$i.contributor_id") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-sm-3">
                                    <select wire:model.live="creditRows.{{ $i }}.role" class="form-select" aria-label="{{ __('Role') }}" @disabled($disabled)>
                                        @foreach ($contributorRoles as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-8 col-sm-3">
                                    @if (in_array($row['role'], $shareRoles, true))
                                        <div class="input-group">
                                            <input type="text" inputmode="decimal" wire:model="creditRows.{{ $i }}.share" maxlength="8" aria-label="{{ __('Share') }}" @disabled($disabled)
                                                   @class(['form-control', 'is-invalid' => $errors->has("creditRows.$i.share")])>
                                            <span class="input-group-text">%</span>
                                        </div>
                                        @error("creditRows.$i.share") <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    @endif
                                </div>
                                @unless ($disabled)
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-light btn-sm text-danger" wire:click="removeCreditRow({{ $i }})" aria-label="{{ __('Remove credit') }}"><x-icon name="x" size="14" /></button>
                                    </div>
                                @endunless
                            </div>
                        @endforeach
                        @unless ($disabled)
                            <div class="d-flex flex-wrap gap-2 align-items-start mt-2">
                                <button type="button" class="btn btn-sm btn-light" wire:click="addCreditRow"><x-icon name="plus" size="14" /> {{ __('Add credit') }}</button>
                                @can('contributors.manage')
                                    <div class="input-group input-group-sm" style="max-width: 320px">
                                        <input type="text" wire:model="newContributor" maxlength="150" placeholder="{{ __('New person’s name') }}"
                                               @class(['form-control', 'is-invalid' => $errors->has('newContributor')]) aria-label="{{ __('New contributor name') }}"
                                               wire:keydown.enter.prevent="createContributor">
                                        <button type="button" class="btn btn-light" wire:click="createContributor">{{ __('Create') }}</button>
                                    </div>
                                    @error('newContributor') <div class="small text-danger w-100">{{ $message }}</div> @enderror
                                @endcan
                            </div>
                        @endunless
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="file-audio" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Master audio') }}</h2>
                    </div>
                    <div class="card-body">
                        @if ($editing && $track->hasAudio())
                            <dl class="ld-dl small mb-3">
                                <dt>{{ __('File') }}</dt><dd class="text-break">{{ $track->audio_original_name }}</dd>
                                <dt>{{ __('Format') }}</dt><dd>{{ strtoupper(str_replace('audio/', '', $track->audio_mime)) }}, {{ number_format($track->audio_sample_rate / 1000, 1) }} kHz, {{ $track->audio_bit_depth }}-bit, {{ $track->audio_channels === 1 ? __('mono') : __('stereo') }}</dd>
                                <dt>{{ __('Length') }}</dt><dd>{{ $track->formattedDuration() }}</dd>
                                <dt>{{ __('Size') }}</dt><dd>{{ App\Support\Format::bytes($track->audio_size) }}</dd>
                            </dl>
                            @if ($canPlay)
                                <audio id="crbt-player" controls preload="none" class="w-100 mb-3" src="{{ route('tracks.audio', $track) }}"></audio>
                            @endif
                        @elseif (! $canEdit)
                            <p class="small text-muted mb-0">{{ __('No audio uploaded.') }}</p>
                        @endif

                        @if ($canEdit)
                            <label for="audio" class="form-label">{{ $editing && $track->hasAudio() ? __('Replace audio') : __('Upload audio') }}</label>
                            <input type="file" id="audio" wire:model="audio" accept=".wav,.flac,audio/wav,audio/flac"
                                   @class(['form-control', 'is-invalid' => $errors->has('audio')])>
                            <div class="form-text">
                                {{ __('WAV or FLAC, at least 44.1 kHz / 16-bit, up to :mb MB.', ['mb' => min($maxMb, $phpLimitMb)]) }}
                                @if ($phpLimitMb < $maxMb)
                                    {{ __('(Limited by the server’s PHP upload settings.)') }}
                                @endif
                            </div>
                            <x-upload-progress for="audio" />
                            @error('audio') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                            @if ($audioInfo)
                                <div class="border rounded-3 p-2 mt-3 small" wire:key="audio-preview">
                                    <div class="fw-semibold mb-1"><x-icon name="circle-check" size="14" class="text-success" /> {{ __('Ready to save') }}</div>
                                    <dl class="ld-dl mb-0">
                                        <dt>{{ __('Length') }}</dt><dd>{{ sprintf('%d:%02d', intdiv($audioInfo['duration'], 60), $audioInfo['duration'] % 60) }}</dd>
                                        <dt>{{ __('Format') }}</dt><dd>{{ strtoupper($audioInfo['format']) }}, {{ number_format($audioInfo['sample_rate'] / 1000, 1) }} kHz, {{ $audioInfo['bit_depth'] }}-bit, {{ $audioInfo['channels'] === 1 ? __('mono') : __('stereo') }}</dd>
                                    </dl>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="card" x-data="{ setFromPlayer() { const a = document.getElementById('crbt-player'); if (! a) return; const t = Math.floor(a.currentTime); $wire.set('crbt_start', Math.floor(t / 60) + ':' + String(t % 60).padStart(2, '0')); } }">
                    <div class="card-header">
                        <x-icon name="phone-call" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Caller tune (CRBT)') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('The 30-second cut used as a caller ring-back tone. Leave empty to start at the beginning.') }}</p>
                        <div class="mb-3">
                            <label for="crbt_name" class="form-label">{{ __('Ring-back tone name') }}</label>
                            <input id="crbt_name" type="text" wire:model="crbt_name" maxlength="64" placeholder="{{ $title ?: __('Same as the track title') }}" @disabled($disabled)
                                   @class(['form-control', 'is-invalid' => $errors->has('crbt_name')])>
                            @error('crbt_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">{{ __('Up to 64 characters.') }}</div>
                        </div>
                        <label for="crbt_start" class="form-label">{{ __('Start time') }}</label>
                        <div class="input-group">
                            <input id="crbt_start" type="text" wire:model="crbt_start" placeholder="0:30" maxlength="6" inputmode="numeric" @disabled($disabled)
                                   @class(['form-control', 'is-invalid' => $errors->has('crbt_start')])>
                            @if ($canPlay && ! $disabled)
                                <button type="button" class="btn btn-light" x-on:click="setFromPlayer()" title="{{ __('Use the player’s current position') }}"><x-icon name="play" size="14" /> {{ __('Use player position') }}</button>
                            @endif
                        </div>
                        @error('crbt_start') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        <div class="form-text">
                            {{ __('minutes:seconds') }}@if ($knownDuration) · {{ __('track length :length', ['length' => sprintf('%d:%02d', intdiv($knownDuration, 60), $knownDuration % 60)]) }}@endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="{{ route('releases.show', ['release' => $release, 'tab' => 'tracks']) }}" class="btn btn-light">{{ $canEdit ? __('Cancel') : __('Back') }}</a>
            @if ($canEdit)
                <button type="button" class="btn btn-light" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft,save,audio">
                    <span wire:loading wire:target="saveDraft" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <x-icon name="save" size="16" /> {{ __('Save as draft') }}
                </button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,saveDraft,audio">
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    {{ $editing ? __('Save track') : __('Add track') }}
                </button>
            @endif
        </div>
    </form>
</div>

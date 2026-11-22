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
                                <input id="title" type="text" wire:model="title" maxlength="200" @disabled($disabled)
                                       @class(['form-control', 'is-invalid' => $errors->has('title')])>
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="version" class="form-label">{{ __('Version') }}</label>
                                <input id="version" type="text" wire:model="version" maxlength="100" placeholder="{{ __('e.g. Radio Edit') }}" @disabled($disabled)
                                       @class(['form-control', 'is-invalid' => $errors->has('version')])>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label for="isrc" class="form-label">{{ __('ISRC') }}</label>
                                <input id="isrc" type="text" wire:model="isrc" maxlength="20" placeholder="CC-XXX-YY-NNNNN" autocomplete="off" @disabled($disabled)
                                       @class(['form-control font-monospace text-uppercase', 'is-invalid' => $errors->has('isrc')])>
                                @error('isrc') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-6 col-md-2">
                                <label for="disc_number" class="form-label">{{ __('Disc') }}</label>
                                <input id="disc_number" type="number" min="1" max="20" wire:model="disc_number" @disabled($disabled)
                                       @class(['form-control', 'is-invalid' => $errors->has('disc_number')])>
                            </div>
                            <div class="col-6 col-md-2">
                                <label for="duration" class="form-label">{{ __('Length') }}</label>
                                <input id="duration" type="text" wire:model="duration" placeholder="3:45" maxlength="7"
                                       @disabled($disabled || ($editing && $track->hasAudio()))
                                       @class(['form-control', 'is-invalid' => $errors->has('duration')])>
                                @error('duration') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
                    </div>
                    <div class="card-body">
                        @include('livewire.catalog.partials.artist-credits')
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <x-icon name="users-round" size="18" class="text-muted" />
                        <h2 class="card-title">{{ __('Songwriters and credits') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('At least one composer, lyricist or songwriter is needed before submitting. Shares apply to publishing roles and may not exceed 100% per role.') }}</p>
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
                <div class="card">
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
                                <audio controls preload="none" class="w-100 mb-3" src="{{ route('tracks.audio', $track) }}"></audio>
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
                            <div wire:loading wire:target="audio" class="small text-muted">{{ __('Uploading… large files can take a while.') }}</div>
                            @error('audio') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <a href="{{ route('releases.show', ['release' => $release, 'tab' => 'tracks']) }}" class="btn btn-light">{{ $canEdit ? __('Cancel') : __('Back') }}</a>
            @if ($canEdit)
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,audio">
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    {{ $editing ? __('Save track') : __('Add track') }}
                </button>
            @endif
        </div>
    </form>
</div>

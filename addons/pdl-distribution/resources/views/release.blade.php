<div @if ($delivery?->isInProgress()) wire:poll.5s @endif>
    <x-page-header :title="$release->displayTitle()" :subtitle="$release->artistLine()">
        <x-slot:actions>
            @can('releases.view')
                <a href="{{ route('releases.show', $release) }}" class="btn btn-light"><x-icon name="disc-3" size="16" /> {{ __('Open release') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @unless ($configured)
        <x-alert variant="warning" class="mb-3">
            {{ __('PDL distribution is not set up or is disabled.') }}
            @can('pdl.manage') <a href="{{ route('settings.pdl') }}">{{ __('Open Settings → PDL distribution') }}</a> @endcan
        </x-alert>
    @endunless

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- Status --}}
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="send" size="18" />
                    <h2 class="card-title">{{ __('Status at PDL') }}</h2>
                    @if ($delivery)
                        <x-badge :variant="$delivery->statusVariant()" class="ms-2">{{ $delivery->statusLabel() }}</x-badge>
                        @can('pdl.deliver')
                            <div class="ms-auto d-flex gap-2">
                                @if ($delivery->status === 'failed')
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="retry" wire:loading.attr="disabled"><x-icon name="rotate-cw" size="14" /> {{ __('Retry') }}</button>
                                @endif
                                @if ($delivery->pdl_album_id)
                                    <button type="button" class="btn btn-sm btn-light" wire:click="checkStatus" wire:loading.attr="disabled" wire:target="checkStatus">
                                        <span wire:loading.remove wire:target="checkStatus"><x-icon name="refresh-cw" size="14" /></span>
                                        <span wire:loading wire:target="checkStatus" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                        {{ __('Check status') }}
                                    </button>
                                @endif
                            </div>
                        @endcan
                    @endif
                </div>
                <div class="card-body">
                    @if (! $delivery)
                        <p class="mb-0 text-muted">{{ __('Not sent to PDL yet.') }}</p>
                    @else
                        <dl class="row mb-0">
                            <dt class="col-sm-4">{{ __('PDL album id') }}</dt>
                            <dd class="col-sm-8 font-mono">{{ $delivery->pdl_album_id ?? '—' }}</dd>
                            <dt class="col-sm-4">{{ __('UPC at PDL') }}</dt>
                            <dd class="col-sm-8 font-mono">{{ $delivery->pdl_upc ?? $release->upc ?? '—' }}</dd>
                            <dt class="col-sm-4">{{ __('Release on') }}</dt>
                            <dd class="col-sm-8">{{ __(\LabelDesk\Addons\PdlDistribution\Services\Cosmos::PLATFORMS[$delivery->platforms] ?? $delivery->platforms) }}</dd>
                            <dt class="col-sm-4">{{ __('Submitted') }}</dt>
                            <dd class="col-sm-8">{{ App\Support\Format::datetime($delivery->submitted_at) }}</dd>
                            @if ($delivery->live_at)
                                <dt class="col-sm-4">{{ __('Live since') }}</dt>
                                <dd class="col-sm-8">{{ App\Support\Format::datetime($delivery->live_at) }}</dd>
                            @endif
                            <dt class="col-sm-4">{{ __('Last status check') }}</dt>
                            <dd class="col-sm-8">{{ App\Support\Format::datetime($delivery->last_synced_at) }}</dd>
                        </dl>

                        @if ($delivery->isInProgress())
                            <x-alert variant="info" class="mt-3 mb-0">
                                {{ __('Sending in the background. You can leave this page.') }}
                                @if ($delivery->next_attempt_at)
                                    {{ __('Waiting to try again at :time.', ['time' => App\Support\Format::datetime($delivery->next_attempt_at)]) }}
                                @endif
                                @if ($uploads->isNotEmpty())
                                    <ul class="mb-0 mt-2 small">
                                        @foreach ($uploads as $u)
                                            <li>@if ($u['done']) <x-icon name="circle-check" size="12" class="text-success" /> @else <x-icon name="clock" size="12" /> @endif {{ $u['filename'] }} ({{ App\Support\Format::bytes($u['size']) }})</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-alert>
                        @endif
                        @if ($delivery->last_error)
                            <x-alert :variant="$delivery->status === 'failed' ? 'danger' : 'warning'" class="mt-3 mb-0">
                                <strong>{{ $delivery->status === 'failed' ? __('Stopped:') : __('Last attempt failed:') }}</strong> {{ $delivery->last_error }}
                            </x-alert>
                        @endif
                        @if ($delivery->issue)
                            <x-alert variant="danger" class="mt-3 mb-0">
                                <strong>{{ $delivery->status === 'release_error' ? __('Store delivery error:') : __('Issue reported by PDL:') }}</strong>
                                <div style="white-space: pre-line">{{ $delivery->issue }}</div>
                            </x-alert>
                        @endif

                        @php($platformRows = (array) data_get($delivery->pdl_state, 'releases', []))
                        @if ($platformRows)
                            <div class="table-responsive mt-3">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>{{ __('PDL platform id') }}</th><th>{{ __('Status') }}</th><th>{{ __('Link') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($platformRows as $row)
                                            <tr>
                                                <td class="font-mono small">{{ $row['platform_id'] }}</td>
                                                <td>{{ __(\LabelDesk\Addons\PdlDistribution\Services\Cosmos::RELEASE_STATUS[$row['release_status']] ?? (string) $row['release_status']) }}
                                                    @if ($row['release_error'] !== '') <div class="small text-danger">{{ $row['release_error'] }}</div> @endif
                                                </td>
                                                <td>@if (str_starts_with($row['live_link'], 'https://') || str_starts_with($row['live_link'], 'http://'))<a href="{{ $row['live_link'] }}" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" size="14" /></a>@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            {{-- History --}}
            @if ($delivery && $delivery->events->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="clock" size="18" />
                        <h2 class="card-title">{{ __('History') }}</h2>
                    </div>
                    <ul class="ld-list">
                        @foreach ($delivery->events as $event)
                            <li class="ld-list__item" wire:key="ev-{{ $event->id }}">
                                <span class="flex-grow-1 min-w-0">
                                    <span @class(['text-danger' => $event->level === 'danger', 'text-warning' => $event->level === 'warning', 'text-success' => $event->level === 'success'])>{{ $event->message }}</span>
                                    <span class="d-block small text-muted">{{ App\Support\Format::datetime($event->created_at) }} @if ($event->user) &middot; {{ $event->user->name }} @endif</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="col-xl-4">
            {{-- Send --}}
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="upload" size="18" />
                    <h2 class="card-title">{{ $isUpdate ? __('Send update to PDL') : __('Send to PDL') }}</h2>
                </div>
                <div class="card-body">
                    @if ($check['problems'])
                        <x-alert variant="warning" class="mb-3">
                            <strong>{{ __('Before it can be sent:') }}</strong>
                            <ul class="mb-0 mt-1 ps-3">@foreach ($check['problems'] as $p) <li>{{ $p }}</li> @endforeach</ul>
                        </x-alert>
                    @endif
                    @if ($problems && $problems !== $check['problems'])
                        <x-alert variant="danger" class="mb-3"><ul class="mb-0 ps-3">@foreach ($problems as $p) <li>{{ $p }}</li> @endforeach</ul></x-alert>
                    @endif
                    @if ($check['warnings'])
                        <ul class="small text-muted ps-3">@foreach ($check['warnings'] as $w) <li>{{ $w }}</li> @endforeach</ul>
                    @endif

                    @if (! $canSend)
                        <p class="text-muted small mb-0">
                            @if ($delivery?->isInProgress())
                                {{ __('Being sent now.') }}
                            @else
                                {{ __('PDL has this release for review or release. The COSMOS API does not accept changes after submission yet; contact PDL for changes.') }}
                            @endif
                        </p>
                    @elseif (auth()->user()->can('pdl.deliver'))
                        <form wire:submit="send" novalidate>
                            <div class="mb-3">
                                <label class="form-label" for="send-platforms">{{ __('Release on') }}</label>
                                <select id="send-platforms" class="form-select @error('platforms') is-invalid @enderror" wire:model="platforms">
                                    @foreach (\LabelDesk\Addons\PdlDistribution\Services\Cosmos::PLATFORMS as $key => $label)
                                        <option value="{{ $key }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                @error('platforms') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="send-mood">{{ __('Mood') }}</label>
                                <select id="send-mood" class="form-select @error('mood') is-invalid @enderror" wire:model="mood">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach (\LabelDesk\Addons\PdlDistribution\Services\Cosmos::MOODS as $m)
                                        <option value="{{ $m }}">{{ $m }}</option>
                                    @endforeach
                                </select>
                                @error('mood') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="send-any-date" wire:model="allow_any_go_live_date">
                                <label class="form-check-label" for="send-any-date">{{ __('Allow any go-live date') }}</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" @disabled(! $configured || $check['problems']) wire:loading.attr="disabled" wire:target="send">
                                <x-icon name="send" size="16" /> {{ $isUpdate ? __('Send update to PDL') : __('Send to PDL') }}
                            </button>
                            <div class="form-text">{{ $isUpdate
                                ? __('Updates the metadata (and artwork if it changed) of album :id, then submits it.', ['id' => $delivery->pdl_album_id])
                                : __('Sends metadata, uploads artwork and audio, verifies and submits the album for release. PDL reviews it before release.') }}</div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

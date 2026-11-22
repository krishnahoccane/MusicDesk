<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="info" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Details') }}</h2>
            </div>
            <div class="card-body py-2">
                <dl class="ld-dl">
                    <dt>{{ __('Artists') }}</dt>
                    <dd>
                        @forelse ($release->artists as $artist)
                            <a href="{{ route('artists.show', $artist) }}">{{ $artist->name }}</a>
                            <span class="text-muted small">({{ App\Enums\ArtistRole::from($artist->pivot->role)->label() }})</span>@if (! $loop->last), @endif
                        @empty — @endforelse
                    </dd>
                    <dt>{{ __('Type') }}</dt><dd>{{ $release->type->label() }}</dd>
                    <dt>{{ __('Release date') }}</dt><dd>{{ App\Support\Format::date($release->release_date) }}</dd>
                    @if ($release->original_release_date)
                        <dt>{{ __('Original release') }}</dt><dd>{{ App\Support\Format::date($release->original_release_date) }}</dd>
                    @endif
                    <dt>{{ __('Genre') }}</dt><dd>{{ $release->genre?->name ?? '—' }}</dd>
                    <dt>{{ __('Language') }}</dt><dd>{{ $release->language?->name ?? '—' }}</dd>
                    <dt>{{ __('UPC / EAN') }}</dt><dd class="font-monospace">{{ $release->upc ?? '—' }}</dd>
                    <dt>{{ __('Catalogue number') }}</dt><dd>{{ $release->catalog_number ?? '—' }}</dd>
                    <dt>{{ __('Label') }}</dt><dd>{{ $release->label_name ?? '—' }}</dd>
                    <dt>{{ __('© line') }}</dt><dd>{{ $release->c_line ? '© '.$release->c_line : '—' }}</dd>
                    <dt>{{ __('℗ line') }}</dt><dd>{{ $release->p_line ? '℗ '.$release->p_line : '—' }}</dd>
                    @if ($release->submitted_at)
                        <dt>{{ __('Submitted') }}</dt><dd>{{ App\Support\Format::datetime($release->submitted_at) }}</dd>
                    @endif
                    @if ($release->approved_at)
                        <dt>{{ __('Approved') }}</dt><dd>{{ App\Support\Format::datetime($release->approved_at) }}</dd>
                    @endif
                </dl>
            </div>
        </div>
        @if ($release->notes)
            <div class="card">
                <div class="card-header">
                    <x-icon name="sticky-note" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Internal notes') }}</h2>
                </div>
                <div class="card-body"><p class="mb-0" style="white-space: pre-line">{{ $release->notes }}</p></div>
            </div>
        @endif
    </div>

    <div class="col-lg-5">
        @if (! $release->trashed())
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="git-pull-request-arrow" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Workflow') }}</h2>
                    <x-badge :variant="$release->status->variant()" class="ms-auto">{{ $release->status->label() }}</x-badge>
                </div>
                <div class="card-body">
                    @if ($pendingAction)
                        <form wire:submit="act('{{ $pendingAction }}')" novalidate>
                            <label for="wf-comment" class="form-label">
                                {{ $pendingAction === 'reject' ? __('What needs to change?') : __('Why reopen this release?') }}
                            </label>
                            <textarea id="wf-comment" wire:model="comment" rows="3" maxlength="2000" autofocus
                                      @class(['form-control mb-2', 'is-invalid' => $errors->has('comment')])></textarea>
                            @error('comment') <div class="invalid-feedback mb-2">{{ $message }}</div> @enderror
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">{{ $actionLabels[$pendingAction] }}</button>
                                <button type="button" class="btn btn-light" wire:click="cancelAction">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @elseif ($actions === [])
                        <p class="small text-muted mb-0">
                            @if ($release->status === App\Enums\ReleaseStatus::Ready)
                                {{ __('This release is ready for delivery.') }}
                            @else
                                {{ __('There is nothing for you to do at this stage.') }}
                            @endif
                        </p>
                    @else
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($actions as $action)
                                <button type="button" wire:click="act('{{ $action }}')" wire:loading.attr="disabled"
                                        @class(['btn', 'btn-primary' => in_array($action, ['submit', 'approve', 'mark_ready', 'start_review'], true), 'btn-light' => ! in_array($action, ['submit', 'approve', 'mark_ready', 'start_review'], true)])
                                        @if (in_array($action, ['withdraw'], true)) wire:confirm="{{ __('Withdraw the submission and return the release to draft?') }}" @endif>
                                    {{ $actionLabels[$action] }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @php($lastRejection = $release->status === App\Enums\ReleaseStatus::Rejected ? $release->history()->where('to_status', 'rejected')->first() : null)
                    @if ($lastRejection?->comment)
                        <x-alert variant="warning" class="mt-3 mb-0" :title="__('Changes requested')">
                            <span style="white-space: pre-line">{{ $lastRejection->comment }}</span>
                        </x-alert>
                    @endif
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <x-icon name="list-checks" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Release checklist') }}</h2>
                @php($done = collect($checklist)->where('ok', true)->count())
                <span class="small text-muted ms-auto">{{ $done }}/{{ count($checklist) }}</span>
            </div>
            <ul class="list-unstyled mb-0 px-4 py-2">
                @foreach ($checklist as $item)
                    <li class="d-flex align-items-center gap-2 py-1 small">
                        <x-icon :name="$item['ok'] ? 'circle-check-big' : 'circle'" size="16" @class([$item['ok'] ? 'text-success' : 'text-subtle']) />
                        <span @class(['text-muted' => ! $item['ok']])>{{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

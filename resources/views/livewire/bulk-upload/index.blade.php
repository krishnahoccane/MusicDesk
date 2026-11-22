<div>
    <x-page-header :title="__('Bulk upload')" :subtitle="$artistAccount
        ? __('Add many releases for your artist profiles at once from a spreadsheet.')
        : __('Add many artists or releases at once from a spreadsheet.')" />

    @if (count($types) > 1)
        <div class="ld-segmented mb-3" role="radiogroup" aria-label="{{ __('What to upload') }}">
            @foreach ($types as $t)
                <input type="radio" id="type-{{ $t }}" value="{{ $t }}" wire:model.live="type">
                <label for="type-{{ $t }}"><x-icon :name="$t === 'artists' ? 'mic-vocal' : 'disc-3'" size="15" /> {{ $t === 'artists' ? __('Artist profiles') : __('Releases & tracks') }}</label>
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-header">
                    <span class="ld-step">1</span>
                    <h2 class="card-title">{{ __('Download the template') }}</h2>
                </div>
                <div class="card-body">
                    <ul class="small text-muted ps-3 mb-3">
                        @foreach ($help as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('bulk-upload.template', [$type, 'xlsx']) }}" class="btn btn-light"><x-icon name="file-spreadsheet" size="16" /> {{ __('Excel template') }}</a>
                        <a href="{{ route('bulk-upload.template', [$type, 'csv']) }}" class="btn btn-light"><x-icon name="file-text" size="16" /> {{ __('CSV template') }}</a>
                    </div>
                    <details class="mt-3 small">
                        <summary class="fw-semibold">{{ __('Columns') }}</summary>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @foreach ($columns as $key => [$label, $required])
                                <x-badge :variant="$required ? 'accent' : 'secondary'">{{ $label }}{{ $required ? ' *' : '' }}</x-badge>
                            @endforeach
                        </div>
                    </details>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <span class="ld-step">2</span>
                    <h2 class="card-title">{{ __('Upload and check') }}</h2>
                </div>
                <form class="card-body" wire:submit="check" novalidate>
                    <input type="file" accept=".csv,.xlsx,text/csv" wire:model="file" @class(['form-control', 'is-invalid' => $errors->has('file')]) aria-label="{{ __('Spreadsheet') }}">
                    @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="small text-muted mt-1" wire:loading wire:target="file">{{ __('Uploading…') }}</div>

                    @if ($type === 'releases' && ! $artistAccount)
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="create-missing" wire:model="createMissing">
                            <label class="form-check-label" for="create-missing">{{ __('Create missing artists (as solo, active profiles)') }}</label>
                        </div>
                    @endif

                    <button type="submit" class="btn btn-primary mt-3" wire:loading.attr="disabled" wire:target="check,file">
                        <span wire:loading wire:target="check" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        {{ __('Check file') }}
                    </button>
                </form>
            </div>

            @if ($preview)
                <div class="card mb-3">
                    <div class="card-header">
                        <span class="ld-step">3</span>
                        <h2 class="card-title">{{ __('Review and import') }}</h2>
                        <span class="small text-muted ms-auto">{{ trans_choice(':count data row|:count data rows', $preview['total']) }}</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <x-badge variant="success" icon="circle-check-big">{{ trans_choice(':count ready to create|:count ready to create', $preview['count']) }}</x-badge>
                            @if ($preview['errorCount'])
                                <x-badge variant="danger" icon="circle-alert">{{ trans_choice(':count row with problems|:count rows with problems', $preview['errorCount']) }}</x-badge>
                            @endif
                            @if ($preview['skipped'])
                                <x-badge variant="secondary">{{ trans_choice(':count already exists|:count already exist', count($preview['skipped'])) }}</x-badge>
                            @endif
                        </div>

                        @if ($preview['missing'])
                            <x-alert variant="info" class="mb-3">{{ __('New artist profiles will be created: :names', ['names' => implode(', ', $preview['missing'])]) }}</x-alert>
                        @endif

                        @if ($preview['errors'])
                            <div class="table-responsive mb-3" style="max-height: 320px">
                                <table class="table table-sm align-top mb-0">
                                    <thead><tr><th style="width: 80px">{{ __('Row') }}</th><th>{{ __('Problem') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($preview['errors'] as $row => $messages)
                                            <tr><td class="text-nowrap">{{ $row }}</td><td class="small text-danger">{{ implode(' ', $messages) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($type === 'releases')
                                <p class="small text-muted">{{ __('A release with a problem in any of its rows is not imported. Fix the file and check it again, or import the valid releases now.') }}</p>
                            @endif
                        @endif

                        @if ($preview['items'])
                            <ul class="ld-list border rounded mb-3" style="max-height: 280px; overflow-y: auto">
                                @foreach ($preview['items'] as $item)
                                    <li class="ld-list__item py-2">
                                        <span class="ld-list__meta" style="width: 56px">{{ __('Row :n', ['n' => $item['row']]) }}</span>
                                        <span class="small fw-semibold">{{ $item['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <button type="button" class="btn btn-primary" wire:click="import" wire:loading.attr="disabled" wire:target="import"
                                    wire:confirm="{{ trans_choice('Create :count record now?|Create :count records now?', $preview['count']) }}">
                                <span wire:loading wire:target="import" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                {{ trans_choice('Import :count record|Import :count records', $preview['count']) }}
                            </button>
                        @else
                            <p class="small text-muted mb-0">{{ __('Nothing in this file can be imported yet.') }}</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <x-icon name="history" size="18" />
                    <h2 class="card-title">{{ __('Recent uploads') }}</h2>
                </div>
                @if ($history->isEmpty())
                    <div class="card-body small text-muted">{{ __('No bulk uploads yet.') }}</div>
                @else
                    <ul class="ld-list">
                        @foreach ($history as $upload)
                            <li class="ld-list__item">
                                <span class="ld-list__icon"><x-icon :name="$upload->type === 'artists' ? 'mic-vocal' : 'disc-3'" size="16" /></span>
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-block small fw-semibold text-truncate">{{ $upload->original_name }}</span>
                                    <span class="ld-list__meta">
                                        {{ trans_choice(':count created|:count created', $upload->created_count) }}
                                        @if ($upload->skipped_count) &middot; {{ trans_choice(':count row skipped|:count rows skipped', $upload->skipped_count) }} @endif
                                        &middot; {{ $upload->user?->name ?? '—' }} &middot; {{ $upload->created_at?->diffForHumans() }}
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>

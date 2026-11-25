<div @if ($busy) wire:poll.5s @endif>
    @if ($uploading)
        <form wire:submit="importFile" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="upload" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Upload a sales statement') }}</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="imp-file" class="form-label">{{ __('Statement file') }}</label>
                        <input id="imp-file" type="file" wire:model="file" accept=".csv,.txt,.tsv,.xlsx" @class(['form-control', 'is-invalid' => $errors->has('file')])>
                        <div class="form-text">{{ __('CSV (comma, semicolon or tab separated) or XLSX, up to :mb MB. The first row must contain column names.', ['mb' => $maxMb]) }}</div>
                        <x-upload-progress for="imp-file" />
                        @error('file') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="imp-profile" class="form-label">{{ __('Saved format') }} <span class="text-muted fw-normal">({{ __('optional') }})</span></label>
                        <select id="imp-profile" wire:model.live="profile_id" class="form-select">
                            <option value="">{{ __('New format — map the columns') }}</option>
                            @foreach ($profiles as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="imp-source" class="form-label">{{ __('Source') }}</label>
                        <input id="imp-source" type="text" wire:model="source_name" maxlength="100" placeholder="{{ __('e.g. Distributor statement, Spotify direct') }}"
                               @class(['form-control', 'is-invalid' => $errors->has('source_name')])>
                        @error('source_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="imp-currency" class="form-label">{{ __('Statement currency') }}</label>
                        <select id="imp-currency" wire:model.live="currency" class="form-select">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($currency !== $baseCurrency)
                        <div class="col-sm-6 col-md-3">
                            <label for="imp-rate" class="form-label">{{ __('1 :from = ? :to', ['from' => $currency, 'to' => $baseCurrency]) }}</label>
                            <input id="imp-rate" type="text" inputmode="decimal" wire:model="exchange_rate" maxlength="20"
                                   @class(['form-control', 'is-invalid' => $errors->has('exchange_rate')])>
                            @error('exchange_rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="$set('uploading', false)">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="importFile,file">{{ __('Upload and map columns') }}</button>
            </div>
        </form>
    @endif

    @if ($mapping)
        <form wire:submit="confirmMapping" class="card mb-3" novalidate>
            <div class="card-header">
                <x-icon name="columns-3" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Map the columns of ":file"', ['file' => $mapping['import']->original_name]) }}</h2>
            </div>
            <div class="card-body">
                @error('map') <x-alert variant="danger" class="mb-3">{{ $message }}</x-alert> @enderror
                <div class="row g-3">
                    @foreach ($fields as $field => [$label, $required])
                        <div class="col-sm-6 col-lg-3" wire:key="map-{{ $field }}">
                            <label for="map-{{ $field }}" class="form-label">{{ $label }} @if ($required)<span class="text-danger">*</span>@endif</label>
                            <select id="map-{{ $field }}" wire:model.live="map.{{ $field }}" class="form-select">
                                <option value="">{{ __('— not in this file —') }}</option>
                                @foreach ($mapping['headers'] as $i => $header)
                                    <option value="{{ $i }}">{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <div class="col-sm-6 col-lg-3">
                        <label for="map-sep" class="form-label">{{ __('Decimal separator') }}</label>
                        <select id="map-sep" wire:model.live="decimal_separator" class="form-select">
                            <option value=".">{{ __('Point (1,234.56)') }}</option>
                            <option value=",">{{ __('Comma (1.234,56)') }}</option>
                        </select>
                    </div>
                </div>
                <p class="small text-muted mt-3 mb-2">{{ __('Sales are matched to tracks by ISRC, or to releases by UPC when there is no ISRC. Rows without an amount or identifier (sub-totals, notes) are skipped.') }}</p>

                <div class="table-responsive border rounded" style="border-color: var(--ld-border) !important">
                    <table class="table table-sm mb-0 small">
                        <thead>
                            <tr>
                                @foreach ($mapping['headers'] as $header)
                                    <th class="text-nowrap">{{ \Illuminate\Support\Str::limit($header, 30) }}</th>
                                @endforeach
                                <th class="text-nowrap">{{ __('→ Net revenue read as') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mapping['preview'] as $r => $row)
                                <tr>
                                    @foreach ($mapping['headers'] as $i => $header)
                                        @php($value = $row[$i] ?? '')
                                        <td class="text-nowrap">{{ \Illuminate\Support\Str::limit($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value, 30) }}</td>
                                    @endforeach
                                    <td class="text-nowrap fw-semibold">{{ isset($mapping['parsed'][$r]) && $mapping['parsed'][$r] !== null ? App\Support\Format::decimal($mapping['parsed'][$r], 4) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label for="map-profile" class="form-label">{{ __('Save this mapping as') }} <span class="text-muted fw-normal">({{ __('optional') }})</span></label>
                        <input id="map-profile" type="text" wire:model="save_profile" maxlength="100" class="form-control" placeholder="{{ __('e.g. Distributor monthly CSV') }}">
                        <div class="form-text">{{ __('Next time, choose it as the saved format and the columns are mapped for you.') }}</div>
                    </div>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancelMapping">{{ __('Map later') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="confirmMapping">
                    <span wire:loading wire:target="confirmMapping" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    {{ __('Import sales') }}
                </button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="card-header">
            <x-icon name="file-spreadsheet" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Statements') }}</h2>
            @if ($canImport && ! $uploading && ! $mapping)
                <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="startUpload"><x-icon name="upload" size="14" /> {{ __('Upload statement') }}</button>
            @endif
        </div>
        @if ($imports->isEmpty())
            <x-empty-state icon="file-spreadsheet" :title="__('No statements yet')" :description="__('Upload the sales reports you received from stores or your distributor for this period.')" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Statement') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end d-none d-md-table-cell">{{ __('Rows') }}</th>
                            <th class="text-end">{{ __('Net revenue') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($imports as $import)
                            <tr wire:key="imp-{{ $import->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $import->source_name }}</div>
                                    <div class="small text-muted text-break">{{ $import->original_name }} &middot; {{ $import->creator?->name }} &middot; {{ App\Support\Format::datetime($import->created_at) }}</div>
                                    @if ($import->status === App\Enums\RoyaltyImportStatus::Failed && $import->error_message)
                                        <div class="small text-danger mt-1">{{ $import->error_message }}</div>
                                    @endif
                                </td>
                                <td>
                                    <x-badge :variant="$import->status->variant()">{{ $import->status->label() }}</x-badge>
                                    @if ($import->status === App\Enums\RoyaltyImportStatus::Processing)
                                        <span class="spinner-border spinner-border-sm ms-1 text-muted" aria-hidden="true"></span>
                                    @endif
                                </td>
                                <td class="text-end d-none d-md-table-cell small">
                                    @if ($import->status === App\Enums\RoyaltyImportStatus::Completed)
                                        {{ number_format($import->total_rows) }}
                                        <div class="text-muted">{{ __(':n matched', ['n' => number_format($import->matched_rows)]) }}@if ($import->skipped_rows) &middot; {{ __(':n skipped', ['n' => number_format($import->skipped_rows)]) }}@endif</div>
                                    @else — @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($import->status === App\Enums\RoyaltyImportStatus::Completed)
                                        {{ App\Support\Format::money((string) $import->net_total, $import->currency) }}
                                        @if ($import->currency !== $baseCurrency)
                                            <div class="small text-muted">{{ App\Support\Format::money((string) $import->net_total_base, $baseCurrency) }} @ {{ rtrim(rtrim((string) $import->exchange_rate, '0'), '.') }}</div>
                                        @endif
                                    @else — @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canImport)
                                        @if (in_array($import->status, [App\Enums\RoyaltyImportStatus::Uploaded, App\Enums\RoyaltyImportStatus::Failed], true))
                                            <button type="button" class="btn btn-sm btn-light" wire:click="openMapping({{ $import->id }})">{{ __('Map columns') }}</button>
                                        @endif
                                        @if ($import->status === App\Enums\RoyaltyImportStatus::Queued)
                                            <button type="button" class="btn btn-sm btn-light" wire:click="processNow({{ $import->id }})" wire:loading.attr="disabled">{{ __('Process now') }}</button>
                                        @endif
                                        @if (in_array($import->status, [App\Enums\RoyaltyImportStatus::Completed, App\Enums\RoyaltyImportStatus::Uploaded, App\Enums\RoyaltyImportStatus::Failed], true))
                                            <button type="button" class="btn btn-sm btn-light text-danger" wire:click="revert({{ $import->id }})"
                                                    wire:confirm="{{ __('Revert ":source"? Its sales are removed from this period; the file and record are kept.', ['source' => $import->source_name]) }}">{{ __('Revert') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

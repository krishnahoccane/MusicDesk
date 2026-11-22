<div>
    <x-page-header :title="$report->title()" :subtitle="$report->description()">
        @if ($canExport)
            <x-slot:actions>
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" @disabled($count === 0)>
                        <x-icon name="download" size="16" /> {{ __('Export') }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ $exportUrls['xlsx'] }}"><x-icon name="file-spreadsheet" size="16" /> {{ __('Excel (.xlsx)') }}</a></li>
                        <li><a class="dropdown-item" href="{{ $exportUrls['csv'] }}"><x-icon name="file-text" size="16" /> {{ __('CSV (.csv)') }}</a></li>
                    </ul>
                </div>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($definitions)
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    @foreach ($definitions as $name => $filter)
                        <div class="col-sm-6 col-lg-3" wire:key="filter-{{ $name }}">
                            <label class="form-label small mb-1" for="filter-{{ $name }}">{{ $filter['label'] }}</label>
                            @if ($filter['type'] === 'select')
                                <select id="filter-{{ $name }}" class="form-select" wire:model.live="filters.{{ $name }}">
                                    @foreach ($filter['options'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="filter-{{ $name }}" type="date" class="form-control" wire:model.live.debounce.400ms="filters.{{ $name }}">
                            @endif
                        </div>
                    @endforeach
                    @if ($activeFilters)
                        <div class="col-auto">
                            <button type="button" class="btn btn-light" wire:click="resetFilters"><x-icon name="rotate-ccw" size="16" /> {{ __('Reset') }}</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <x-icon :name="$report->icon()" size="18" />
            <h2 class="card-title">{{ trans_choice(':count row|:count rows', $count) }}</h2>
            <span class="small text-muted ms-auto" wire:loading.delay>{{ __('Updating…') }}</span>
            @if (collect($columns)->contains('type', 'money'))
                <span class="small text-muted ms-auto" wire:loading.remove>{{ __('Amounts in :currency', ['currency' => $currency]) }}</span>
            @endif
        </div>

        @if ($count === 0)
            <x-empty-state :icon="$report->icon()" :title="__('Nothing to report')" :description="__('No records match these filters.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 ld-report-table">
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th @class(['text-end' => in_array($column['type'], ['int', 'money', 'percent'], true), 'text-nowrap'])>{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($columns as $key => $column)
                                    @php($value = $row[$key] ?? null)
                                    <td @class(['text-end text-nowrap' => in_array($column['type'], ['int', 'money', 'percent'], true), 'text-nowrap' => $column['type'] === 'date'])>
                                        @if ($loop->first && ! empty($row['_url']))
                                            <a href="{{ $row['_url'] }}" class="fw-semibold">{{ $value ?? '—' }}</a>
                                        @elseif ($column['type'] === 'badge')
                                            @if ($value) <x-badge :variant="$value[1] ?? 'secondary'">{{ $value[0] }}</x-badge> @else — @endif
                                        @elseif ($column['type'] === 'money')
                                            {{ App\Support\Format::decimal($value) }}
                                        @elseif ($column['type'] === 'percent')
                                            {{ App\Support\Format::percent($value) }}
                                        @elseif ($column['type'] === 'int')
                                            {{ number_format((int) $value) }}
                                        @elseif ($column['type'] === 'date')
                                            {{ App\Support\Format::date($value) }}
                                        @else
                                            <span @class(['fw-semibold' => $loop->first])>{{ filled($value) ? $value : '—' }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($totals)
                        <tfoot>
                            <tr>
                                @foreach ($columns as $key => $column)
                                    <th @class(['text-end text-nowrap' => array_key_exists($key, $totals)])>
                                        @if (array_key_exists($key, $totals))
                                            {{ match ($column['type']) {
                                                'money' => App\Support\Format::decimal($totals[$key]),
                                                'percent' => App\Support\Format::percent($totals[$key]),
                                                default => number_format((int) $totals[$key]),
                                            } }}
                                        @elseif ($loop->first)
                                            {{ __('Total') }}
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @if ($count > count($rows))
                <div class="card-footer small text-muted">
                    {{ __('Showing the first :shown of :total rows. Export to get every row.', ['shown' => count($rows), 'total' => number_format($count)]) }}
                </div>
            @endif
        @endif
    </div>
</div>

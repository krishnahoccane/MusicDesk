<div>
    <x-page-header :title="__('Reports')" :subtitle="__('Filter any report on screen, then export it to CSV or Excel.')" />

    @if ($groups === [])
        <div class="card">
            <x-empty-state icon="chart-column" :title="__('No reports available')" :description="__('Your role does not include any report areas. Ask an administrator if you need access.')" />
        </div>
    @endif

    @foreach ($groups as $group => $items)
        <h2 class="ld-section-title">{{ App\Reports\ReportRegistry::groupLabel($group) }}</h2>
        <div class="row g-3 mb-4">
            @foreach ($items as ['report' => $report, 'locked' => $locked])
                <div class="col-md-6 col-xl-4" wire:key="report-{{ $report->key() }}">
                    @if ($locked)
                        <div class="ld-report-card is-locked" aria-disabled="true">
                            <span class="ld-report-card__icon"><x-icon :name="$report->icon()" size="20" /></span>
                            <span class="flex-grow-1 min-w-0">
                                <span class="ld-report-card__title">{{ $report->title() }}</span>
                                <span class="ld-report-card__text">{{ $report->description() }}</span>
                                <span class="ld-report-card__lock"><x-icon name="lock" size="13" /> {{ __('Not included in your license plan') }}</span>
                            </span>
                        </div>
                    @else
                        <a href="{{ route('reports.show', $report->key()) }}" class="ld-report-card">
                            <span class="ld-report-card__icon"><x-icon :name="$report->icon()" size="20" /></span>
                            <span class="flex-grow-1 min-w-0">
                                <span class="ld-report-card__title">{{ $report->title() }}</span>
                                <span class="ld-report-card__text">{{ $report->description() }}</span>
                            </span>
                            <x-icon name="arrow-right" size="18" class="ld-report-card__arrow" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($exports->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <x-icon name="download" size="18" />
                <h2 class="card-title">{{ __('Your recent exports') }}</h2>
            </div>
            <ul class="ld-list">
                @foreach ($exports as $export)
                    @php($definition = $registry->find($export->report))
                    <li class="ld-list__item">
                        <span class="ld-list__icon"><x-icon :name="$export->format === 'xlsx' ? 'file-spreadsheet' : 'file-text'" size="16" /></span>
                        <span class="flex-grow-1 min-w-0">
                            <span class="d-block fw-semibold small">{{ $definition?->title() ?? $export->report }}</span>
                            <span class="ld-list__meta">{{ strtoupper($export->format) }} &middot; {{ trans_choice(':count row|:count rows', $export->row_count) }} &middot; {{ $export->created_at?->diffForHumans() }}</span>
                        </span>
                        @if ($definition && $definition->licensed())
                            <a href="{{ route('reports.show', ['report' => $export->report, 'f' => $export->filters ?? []]) }}" class="btn btn-sm btn-light">{{ __('Open') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

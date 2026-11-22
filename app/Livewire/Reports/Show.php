<?php

namespace App\Livewire\Reports;

use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One report: filters, an on-screen preview and CSV / XLSX export.
 */
class Show extends Component
{
    /** Rows shown on screen; exports always contain every row. */
    public const PREVIEW_LIMIT = 250;

    #[Locked]
    public string $key = '';

    #[Url(as: 'f', except: [])]
    public array $filters = [];

    public function mount(string $report, ReportRegistry $registry): void
    {
        $definition = $registry->find($report);
        abort_unless($definition && $definition->permitted(auth()->user()), $definition ? 403 : 404);
        abort_unless($definition->licensed(), 403, __('This report is not included in your license plan.'));

        $this->key = $report;
        $this->filters = $definition->normalize($this->filters);
    }

    public function resetFilters(): void
    {
        $this->filters = $this->report()->normalize([]);
    }

    protected function report(): Report
    {
        return app(ReportRegistry::class)->find($this->key);
    }

    public function render(): View
    {
        $report = $this->report();
        $filters = $report->normalize($this->filters);
        $columns = $report->columns($filters);

        $rows = [];
        $count = 0;
        $totals = array_fill_keys(array_intersect($report->totals(), array_keys($columns)), '0');
        foreach ($report->rows($filters) as $row) {
            $count++;
            foreach ($totals as $key => $sum) {
                $totals[$key] = Decimal::add($sum, (string) ($row[$key] ?? '0'));
            }
            if ($count <= self::PREVIEW_LIMIT) {
                $rows[] = $row;
            }
        }

        $exportQuery = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        return view('livewire.reports.show', [
            'report' => $report,
            'definitions' => $report->filters(),
            'columns' => $columns,
            'rows' => $rows,
            'count' => $count,
            'totals' => $count > 0 ? $totals : [],
            'currency' => config('labeldesk.currency'),
            'canExport' => auth()->user()->can('reports.export'),
            'exportUrls' => [
                'csv' => route('reports.export', ['report' => $this->key, 'format' => 'csv'] + ['f' => $exportQuery]),
                'xlsx' => route('reports.export', ['report' => $this->key, 'format' => 'xlsx'] + ['f' => $exportQuery]),
            ],
            'activeFilters' => count(array_filter($filters, fn ($v, $k) => $v !== null && $v !== '' && $v !== ($report->filters()[$k]['default'] ?? null), ARRAY_FILTER_USE_BOTH)),
        ])->title($report->title())->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Reports'), 'url' => route('reports.index')], ['label' => $report->title()],
        ]]);
    }
}

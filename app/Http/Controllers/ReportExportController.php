<?php

namespace App\Http\Controllers;

use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, ReportRegistry $registry, ReportExporter $exporter): StreamedResponse
    {
        $definition = $registry->find($report);
        abort_unless($definition, 404);
        abort_unless($definition->permitted($request->user()) && $request->user()->can('reports.export'), 403);
        abort_unless($definition->licensed(), 403, __('This report is not included in your license plan.'));

        $filters = $definition->normalize((array) $request->query('f', []));

        return $exporter->download($definition, $filters, $format, $request->user(), $request->ip());
    }
}

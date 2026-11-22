<?php

namespace App\Reports;

use App\Models\ExportLog;
use App\Models\User;
use App\Support\Decimal;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report as CSV or XLSX (row by row, so large reports do not need
 * to fit in memory) and records the export in export_logs.
 */
class ReportExporter
{
    public function download(Report $report, array $filters, string $format, User $user, ?string $ip = null): StreamedResponse
    {
        $columns = $report->columns($filters);
        $filename = $report->key().'-'.now()->format('Y-m-d').'.'.$format;

        $log = ExportLog::query()->create([
            'user_id' => $user->id,
            'report' => $report->key(),
            'format' => $format,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'ip_address' => $ip,
        ]);

        return response()->streamDownload(function () use ($report, $filters, $format, $columns, $log) {
            $count = $this->write($report, $filters, $format, $columns, 'php://output');
            $log->update(['row_count' => $count]);
        }, $filename, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return int Number of data rows written
     */
    public function write(Report $report, array $filters, string $format, array $columns, string $target): int
    {
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($target);

        if ($writer instanceof XlsxWriter) {
            $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $report->title()), 0, 31));
        }

        $bold = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(array_column($columns, 'label'), $bold));

        $count = 0;
        $totals = array_fill_keys(array_intersect($report->totals(), array_keys($columns)), '0');

        foreach ($report->rows($filters) as $row) {
            $cells = [];
            foreach ($columns as $key => $column) {
                $value = Report::exportValue($row[$key] ?? null, $column['type']);
                // Numbers as numbers so spreadsheets can sum them.
                $cells[] = in_array($column['type'], ['money', 'percent'], true) && $value !== null ? (float) $value : $value;
                if (array_key_exists($key, $totals)) {
                    $totals[$key] = Decimal::add($totals[$key], (string) ($row[$key] ?? '0'));
                }
            }
            $writer->addRow(Row::fromValues($cells));
            $count++;
        }

        if ($totals !== [] && $count > 0) {
            $cells = [];
            foreach (array_keys($columns) as $i => $key) {
                $cells[] = array_key_exists($key, $totals)
                    ? ($columns[$key]['type'] === 'int' ? (int) $totals[$key] : (float) Decimal::round($totals[$key], 2))
                    : ($i === 0 ? __('Total') : '');
            }
            $writer->addRow(Row::fromValues($cells, $bold));
        }

        $writer->close();

        return $count;
    }
}

<?php

namespace LabelDesk\Addons\BulkUpload\Services;

use App\Services\Royalties\StatementReader;
use DateTimeInterface;
use RuntimeException;

/**
 * Reads a bulk upload spreadsheet (CSV or XLSX) into rows keyed by the
 * template's column keys. Header names are matched loosely ("Release title",
 * "release_title" and "RELEASE TITLE" are the same column).
 */
class BulkSheet
{
    public const MAX_ROWS = 2000;

    /**
     * @param  array<string, array{0: string}>  $definitions  column key => [heading, …]
     * @return list<array{row: int, data: array<string, string>}>
     */
    public static function read(string $path, string $extension, array $definitions): array
    {
        $reader = StatementReader::for($path, $extension);
        $columns = array_keys($definitions);

        // A heading matches a column by its key ("release_title") or its label ("Release title").
        $lookup = [];
        foreach ($definitions as $key => $definition) {
            $lookup[$key] = $key;
            $lookup[self::key((string) $definition[0])] ??= $key;
        }

        $positions = [];
        foreach ($reader->headers() as $index => $header) {
            $key = $lookup[self::key($header)] ?? null;
            if ($key !== null && ! isset($positions[$key])) {
                $positions[$key] = $index;
            }
        }

        if ($positions === []) {
            throw new RuntimeException(__('None of the column headings match the template. Download the template and keep its first row.'));
        }

        $rows = [];
        foreach ($reader->rows() as [$number, $values]) {
            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException(__('A file can have at most :max rows. Split it into smaller files.', ['max' => number_format(self::MAX_ROWS)]));
            }

            $data = [];
            foreach ($columns as $column) {
                $data[$column] = isset($positions[$column]) ? self::cell($values[$positions[$column]] ?? null) : '';
            }
            $rows[] = ['row' => $number, 'data' => $data];
        }

        if ($rows === []) {
            throw new RuntimeException(__('The file has a heading row but no data rows.'));
        }

        return $rows;
    }

    public static function key(string $header): string
    {
        $header = preg_replace('/^\x{FEFF}/u', '', $header) ?? $header;
        $header = preg_replace('/\s*\*\s*$/', '', $header) ?? $header; // "Name *" (required marker)

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))), '_');
    }

    /**
     * Spreadsheet cell → trimmed string. Whole numbers (codes typed into
     * Excel) lose their ".0"; dates become YYYY-MM-DD.
     */
    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'yes' : 'no',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => number_format($value, 0, '', ''),
            default => trim((string) $value),
        };
    }

    public static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'y', 'true', 'explicit', 'x'], true);
    }

    /**
     * "3:25", "1:02:03" or "205" → seconds.
     */
    public static function duration(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $value, $m)) {
            return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
        }

        return -1; // invalid
    }

    /**
     * "A; B, C" → ["A", "B", "C"]
     *
     * @return list<string>
     */
    public static function names(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;|]/', $value) ?: []), fn ($n) => $n !== ''));
    }
}

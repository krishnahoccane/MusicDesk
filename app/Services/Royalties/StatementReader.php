<?php

namespace App\Services\Royalties;

use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

/**
 * Streams rows from a CSV/TSV or XLSX sales statement with OpenSpout (low
 * memory, no full-file loading). The first non-empty row is the header row.
 */
class StatementReader
{
    public const EXTENSIONS = ['csv', 'txt', 'tsv', 'xlsx'];

    public function __construct(protected string $path, protected string $extension) {}

    public static function for(string $path, string $extension): self
    {
        $extension = strtolower($extension);
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException(__('Upload a CSV or XLSX file.'));
        }

        return new self($path, $extension);
    }

    protected function open(): ReaderInterface
    {
        try {
            if ($this->extension === 'xlsx') {
                $options = new XlsxOptions;
                $options->SHOULD_FORMAT_DATES = false;
                $reader = new XlsxReader($options);
            } else {
                $options = new CsvOptions;
                $options->FIELD_DELIMITER = $this->detectDelimiter();
                $reader = new CsvReader($options);
            }
            $reader->open($this->path);
        } catch (Throwable $e) {
            throw new RuntimeException(__('The file could not be read. Is it a valid CSV or XLSX file?'), 0, $e);
        }

        return $reader;
    }

    /**
     * Picks the separator that appears most often in the first line.
     */
    public function detectDelimiter(): string
    {
        $handle = @fopen($this->path, 'rb');
        $line = $handle ? (string) fgets($handle, 65536) : '';
        if ($handle) {
            fclose($handle);
        }

        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), '|' => substr_count($line, '|')];
        arsort($counts);

        return (string) array_key_first($counts) ?: ',';
    }

    /**
     * Yields [row number (1-based, header = 1), list of cell values] for data rows.
     *
     * @return Generator<int, array{0: int, 1: list<mixed>}>
     */
    public function rows(): Generator
    {
        $reader = $this->open();
        $number = 0;
        $headerSeen = false;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $values = $row->toArray();
                    if (! $this->hasContent($values)) {
                        continue;
                    }
                    if (! $headerSeen) {
                        $headerSeen = true;

                        continue;
                    }

                    yield [$number, $values];
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        $reader = $this->open();

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if ($this->hasContent($values)) {
                        $headers = [];
                        foreach ($values as $i => $value) {
                            $label = trim(preg_replace('/^\x{FEFF}/u', '', (string) $value) ?? '');
                            $headers[] = $label !== '' ? $label : __('Column :n', ['n' => $i + 1]);
                        }

                        return $headers;
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }

        throw new RuntimeException(__('The file is empty.'));
    }

    /**
     * @return list<list<mixed>>
     */
    public function preview(int $limit = 5): array
    {
        $rows = [];
        foreach ($this->rows() as [, $values]) {
            $rows[] = $values;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $values
     */
    protected function hasContent(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) (is_object($value) ? 'x' : $value)) !== '') {
                return true;
            }
        }

        return false;
    }
}

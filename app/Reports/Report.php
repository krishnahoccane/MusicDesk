<?php

namespace App\Reports;

use App\Models\User;
use App\Services\License\LicenseService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A filterable, exportable report.
 *
 * Rows are plain arrays keyed by column. Money is a decimal string (label
 * currency), counts are ints, dates are DateTimeInterface. A column of type
 * "badge" holds [label, variant]. A row may carry "_url" to link its first
 * column on screen.
 */
abstract class Report
{
    /** Column types: text, int, money, percent, date, badge. */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /**
     * @return array<string, array{label: string, type: string}>
     */
    abstract public function columns(array $filters): array;

    /**
     * @return iterable<int, array<string, mixed>>
     */
    abstract public function rows(array $filters): iterable;

    public function icon(): string
    {
        return 'file-chart-column';
    }

    /** catalogue | distribution | royalties | contracts */
    public function group(): string
    {
        return 'catalogue';
    }

    /**
     * License features that must all be included in the plan.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return ['reports'];
    }

    /**
     * Permissions required in addition to reports.view.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * @return array<string, array{label: string, type: string, options?: array<string, string>, default?: mixed}>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Columns summed into a totals row.
     *
     * @return list<string>
     */
    public function totals(): array
    {
        return [];
    }

    public function licensed(): bool
    {
        $license = app(LicenseService::class);

        foreach ($this->features() as $feature) {
            if (! $license->canUse($feature)) {
                return false;
            }
        }

        return true;
    }

    public function permitted(User $user): bool
    {
        if (! $user->can('reports.view')) {
            return false;
        }

        foreach ($this->permissions() as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keep only known filter values; anything else falls back to the default.
     *
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $clean = [];

        foreach ($this->filters() as $key => $filter) {
            $value = $input[$key] ?? null;
            $default = $filter['default'] ?? null;

            $clean[$key] = match ($filter['type']) {
                'select' => is_scalar($value) && array_key_exists((string) $value, $filter['options'] ?? []) ? (string) $value : $default,
                'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $default,
                default => $default,
            };
        }

        return $clean;
    }

    /**
     * Plain value for a cell in exports.
     */
    public static function exportValue(mixed $value, string $type): string|int|float|null
    {
        return match (true) {
            $value === null => null,
            $type === 'badge' && is_array($value) => (string) $value[0],
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->format('Y-m-d'),
            $type === 'money' => Decimal::round((string) $value, 2),
            $type === 'percent' => Decimal::round((string) $value, 2),
            is_int($value) => $value,
            default => (string) $value,
        };
    }

    /**
     * Normalise an SQL aggregate (exact on MySQL, float on SQLite) to a decimal string.
     */
    protected static function dec(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return Decimal::round(is_float($value) ? sprintf('%.6F', $value) : (string) $value, 6);
    }

    protected static function date(mixed $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }
}

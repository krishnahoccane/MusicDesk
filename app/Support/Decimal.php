<?php

namespace App\Support;

/**
 * Exact decimal arithmetic on numeric strings (BCMath). Money never passes
 * through floats; rounding is half away from zero.
 */
class Decimal
{
    public const SCALE = 6;

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul($a, $b, $scale + 4), $scale);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::round(bcdiv($a, $b, $scale + 4), $scale);
    }

    /**
     * $amount × $percent / 100, rounded.
     */
    public static function percentOf(string $amount, string $percent, int $scale = self::SCALE): string
    {
        return self::round(bcdiv(bcmul($amount, $percent, $scale + 6), '100', $scale + 4), $scale);
    }

    public static function round(string $value, int $scale = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with(ltrim($value), '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    public static function isZero(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) === 0;
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /**
     * Sums an iterable of numeric strings exactly.
     *
     * @param  iterable<string|int|null>  $values
     */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = '0';
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                $total = bcadd($total, (string) $value, $scale);
            }
        }

        return bcadd($total, '0', $scale);
    }

    /**
     * Parses an amount from a statement cell: "1,234.56", "1.234,56" (with
     * decimal separator ","), "(12.50)", "-3", "12.5-", "$ 7.10", 0.1 (float
     * from a spreadsheet). Returns null when the cell holds no number.
     */
    public static function parse(mixed $value, string $decimalSeparator = '.'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            // Spreadsheets store doubles; 10 places keeps every real-world amount exact.
            return self::round(sprintf('%.10F', $value), 10);
        }

        $text = trim((string) $value);
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }
        if (str_ends_with($text, '-')) {
            $negative = true;
            $text = substr($text, 0, -1);
        }
        if (str_starts_with(ltrim($text), '-')) {
            $negative = ! $negative;
            $text = ltrim(ltrim($text), '-');
        }

        $thousands = $decimalSeparator === ',' ? '.' : ',';
        $text = str_replace([$thousands, ' ', "\u{00A0}", "'"], '', $text);
        if ($decimalSeparator === ',') {
            $text = str_replace(',', '.', $text);
        }
        $text = preg_replace('/[^0-9.]/', '', $text) ?? '';

        if ($text === '' || ! preg_match('/^\d*\.?\d*$/', $text) || $text === '.') {
            return null;
        }

        $number = bcadd(str_starts_with($text, '.') ? '0'.$text : $text, '0', 10);

        return $negative ? bcmul($number, '-1', 10) : $number;
    }
}

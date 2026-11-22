<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Display formatting. Timestamps are stored in UTC and shown in the label's
 * display timezone using the configured formats.
 */
class Format
{
    public static function datetime(?DateTimeInterface $value, string $fallback = '—'): string
    {
        return $value
            ? Carbon::instance($value)->setTimezone(config('labeldesk.display_timezone'))->format(config('labeldesk.datetime_format'))
            : $fallback;
    }

    /**
     * Human-readable file size (no intl dependency).
     */
    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) max(0, $bytes);
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$i];
    }

    /**
     * Formats a DECIMAL string for display without converting it to a float.
     */
    public static function decimal(?string $value, int $places = 2, string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $rounded = bcadd($value, '0', $places + 1);
        // Round half up at the requested precision.
        $factor = '0.'.str_repeat('0', $places).'5';
        $rounded = str_starts_with($rounded, '-') ? bcsub($rounded, $factor, $places) : bcadd($rounded, $factor, $places);

        [$int, $frac] = array_pad(explode('.', $rounded), 2, '');
        $negative = str_starts_with($int, '-');
        $int = ltrim($int, '-');
        $grouped = strrev(implode(',', str_split(strrev($int), 3)));

        return ($negative && trim($int.$frac, '0') !== '' ? '-' : '').$grouped.($places > 0 ? '.'.$frac : '');
    }

    public static function money(?string $amount, ?string $currency = null, string $fallback = '—'): string
    {
        if ($amount === null || $amount === '') {
            return $fallback;
        }

        return self::decimal($amount, 2).' '.($currency ?: config('labeldesk.currency'));
    }

    /**
     * Percentages such as "70.0000" → "70%" and "12.5000" → "12.5%".
     */
    public static function percent(?string $value, string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $trimmed = rtrim(rtrim(bcadd($value, '0', 4), '0'), '.');

        return ($trimmed === '' || $trimmed === '-' ? '0' : $trimmed).'%';
    }

    public static function date(?DateTimeInterface $value, string $fallback = '—'): string
    {
        return $value
            ? Carbon::instance($value)->setTimezone(config('labeldesk.display_timezone'))->format(config('labeldesk.date_format'))
            : $fallback;
    }
}

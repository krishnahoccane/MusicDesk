<?php

namespace App\Support;

/**
 * Industry identifiers.
 *
 * ISRC  (ISO 3901): CC-XXX-YY-NNNNN → stored as 12 characters without hyphens:
 *        2-letter country code, 3 alphanumeric registrant, 2-digit year, 5-digit designation.
 * UPC/EAN (GTIN-12/13): digits with a mod-10 check digit.
 */
class Identifiers
{
    public static function normalizeIsrc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[\s\-]/', '', $value) ?? '');

        return $clean === '' ? null : $clean;
    }

    public static function isValidIsrc(string $normalized): bool
    {
        return (bool) preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{2}\d{5}$/', $normalized);
    }

    public static function normalizeUpc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[\s\-]/', '', $value) ?? '';

        return $clean === '' ? null : $clean;
    }

    public static function isValidUpc(string $normalized): bool
    {
        if (! preg_match('/^\d{12,13}$/', $normalized)) {
            return false;
        }

        $digits = array_map('intval', str_split($normalized));
        $check = array_pop($digits);

        // From the right (excluding the check digit), weights alternate 3, 1, 3 …
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }
}

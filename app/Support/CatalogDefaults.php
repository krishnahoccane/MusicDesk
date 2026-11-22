<?php

namespace App\Support;

use App\Services\SettingsService;

/**
 * Label defaults for new releases (Settings → Catalogue).
 */
final class CatalogDefaults
{
    public const DEFAULT_LINE = '{year} {label}';

    public static function labelName(): string
    {
        $name = trim((string) app(SettingsService::class)->get('catalog.label_name', ''));

        return $name !== '' ? $name : (string) config('app.name');
    }

    public static function cLine(?int $year = null): string
    {
        return self::line((string) app(SettingsService::class)->get('catalog.c_line_format', self::DEFAULT_LINE), $year);
    }

    public static function pLine(?int $year = null): string
    {
        return self::line((string) app(SettingsService::class)->get('catalog.p_line_format', self::DEFAULT_LINE), $year);
    }

    public static function languageCode(): string
    {
        return (string) app(SettingsService::class)->get('catalog.default_language', 'en');
    }

    /**
     * "{year} {label}" → "2026 Sunrise Records". Unknown placeholders stay as typed.
     */
    public static function line(string $format, ?int $year = null): string
    {
        $format = trim($format) !== '' ? $format : self::DEFAULT_LINE;

        return trim(strtr($format, ['{year}' => (string) ($year ?? now()->year), '{label}' => self::labelName()]));
    }
}

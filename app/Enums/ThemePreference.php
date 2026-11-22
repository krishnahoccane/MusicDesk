<?php

namespace App\Enums;

enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => __('Light'),
            self::Dark => __('Dark'),
            self::System => __('System'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Light => 'sun',
            self::Dark => 'moon',
            self::System => 'monitor',
        };
    }

    public static function default(): self
    {
        return self::tryFrom((string) config('labeldesk.appearance.default_mode', config('labeldesk.default_theme'))) ?? self::System;
    }
}

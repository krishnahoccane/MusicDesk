<?php

namespace App\Support;

use App\Enums\ThemePreference;
use App\Models\User;

/**
 * Administrator-controlled look of the application (Settings → Appearance):
 * colour theme, which colour modes are allowed, the default mode and the
 * sidebar style. Values come from config('labeldesk.appearance'), which
 * SettingsApplier fills from the settings table.
 */
class Appearance
{
    /**
     * Theme key => [label, light accent, light accent 2, dark accent, dark accent 2, light bg, dark bg].
     * Colours here only drive the previews; the real values are in resources/scss/_themes.scss.
     */
    public const THEMES = [
        'aurora' => ['Aurora', '#6d5efc', '#14b8a6', '#8f82ff', '#2dd4bf', '#f5f6fa', '#0c0e13'],
        'ocean' => ['Ocean', '#2563eb', '#06b6d4', '#60a5fa', '#22d3ee', '#f5f6fa', '#0c0e13'],
        'emerald' => ['Emerald', '#059669', '#65a30d', '#34d399', '#a3e635', '#f5f6fa', '#0c0e13'],
        'sunset' => ['Sunset', '#ea580c', '#db2777', '#fb923c', '#f472b6', '#f5f6fa', '#0c0e13'],
        'rose' => ['Rose', '#e11d48', '#8b5cf6', '#fb7185', '#a78bfa', '#f5f6fa', '#0c0e13'],
        'midnight' => ['Midnight', '#4f46e5', '#0ea5e9', '#818cf8', '#38bdf8', '#f3f5fb', '#070b16'],
        'graphite' => ['Graphite', '#18181b', '#71717a', '#f4f4f5', '#a1a1aa', '#f6f6f7', '#0a0a0b'],
        'sand' => ['Sand', '#b45309', '#0f766e', '#f59e0b', '#2dd4bf', '#f7f4ee', '#100d09'],
    ];

    public const MODES = ['both', 'light', 'dark'];

    public const SIDEBARS = ['light', 'tinted', 'dark'];

    public static function theme(): string
    {
        $theme = (string) config('labeldesk.appearance.theme', 'aurora');

        return array_key_exists($theme, self::THEMES) ? $theme : 'aurora';
    }

    /** "both" (users choose), "light" only or "dark" only. */
    public static function modes(): string
    {
        $modes = (string) config('labeldesk.appearance.modes', 'both');

        return in_array($modes, self::MODES, true) ? $modes : 'both';
    }

    public static function sidebar(): string
    {
        $sidebar = (string) config('labeldesk.appearance.sidebar', 'light');

        return in_array($sidebar, self::SIDEBARS, true) ? $sidebar : 'light';
    }

    public static function defaultMode(): ThemePreference
    {
        return ThemePreference::tryFrom((string) config('labeldesk.appearance.default_mode', 'system')) ?? ThemePreference::System;
    }

    public static function allowsToggle(): bool
    {
        return self::modes() === 'both';
    }

    /**
     * The colour-mode preference that applies for this user: forced when the
     * administrator allows only one mode, otherwise the user's own choice.
     */
    public static function preferenceFor(?User $user): string
    {
        return match (self::modes()) {
            'light' => ThemePreference::Light->value,
            'dark' => ThemePreference::Dark->value,
            default => ($user?->theme instanceof ThemePreference ? $user->theme : self::defaultMode())->value,
        };
    }

    /**
     * Attributes for the <html> element.
     *
     * @return array{theme: string, sidebar: string, preference: string, toggle: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'theme' => self::theme(),
            'sidebar' => self::sidebar(),
            'preference' => self::preferenceFor($user),
            'toggle' => self::allowsToggle(),
        ];
    }
}

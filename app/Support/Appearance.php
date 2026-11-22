<?php

namespace App\Support;

use App\Enums\ThemePreference;
use App\Models\User;

/**
 * Administrator-controlled look of the application (Settings → Appearance and
 * the installer's customisation step): colour theme or custom brand colours,
 * font, allowed colour modes and default, sidebar style, logo, favicon and the
 * sign-in page design and texts. Values come from config('labeldesk.appearance'),
 * which SettingsApplier fills from the settings table.
 */
class Appearance
{
    /**
     * Theme key => [label, light accent, light accent 2, dark accent, dark accent 2, light bg, dark bg].
     * Colours here only drive the previews; the real values are in resources/scss.
     */
    public const THEMES = [
        'musicdesk' => ['MusicDesk', '#6440f0', '#e0249f', '#7c5cff', '#ff3fd2', '#f6f6fa', '#07070b'],
        'aurora' => ['Aurora', '#6d5efc', '#14b8a6', '#8f82ff', '#2dd4bf', '#f5f6fa', '#0c0e13'],
        'ocean' => ['Ocean', '#2563eb', '#06b6d4', '#60a5fa', '#22d3ee', '#f5f6fa', '#0c0e13'],
        'emerald' => ['Emerald', '#059669', '#65a30d', '#34d399', '#a3e635', '#f5f6fa', '#0c0e13'],
        'sunset' => ['Sunset', '#ea580c', '#db2777', '#fb923c', '#f472b6', '#f5f6fa', '#0c0e13'],
        'rose' => ['Rose', '#e11d48', '#8b5cf6', '#fb7185', '#a78bfa', '#f5f6fa', '#0c0e13'],
        'midnight' => ['Midnight', '#4f46e5', '#0ea5e9', '#818cf8', '#38bdf8', '#f3f5fb', '#070b16'],
        'graphite' => ['Graphite', '#18181b', '#71717a', '#f4f4f5', '#a1a1aa', '#f6f6f7', '#0a0a0b'],
        'sand' => ['Sand', '#b45309', '#0f766e', '#f59e0b', '#2dd4bf', '#f7f4ee', '#100d09'],
    ];

    public const DEFAULT_THEME = 'musicdesk';

    public const MODES = ['both', 'light', 'dark'];

    public const SIDEBARS = ['light', 'tinted', 'dark'];

    public const FONTS = ['inter-tight' => 'Inter Tight', 'system' => 'System font'];

    /** Sign-in page designs (resources/views/components/layouts/auth/*). */
    public const LOGIN_LAYOUTS = [
        'spotlight' => ['Spotlight', 'Centred glass card over a glowing grid.'],
        'split' => ['Split', 'Brand story on the left, form on the right.'],
        'cover' => ['Cover', 'Full-screen photo or artwork with a floating form.'],
        'studio' => ['Studio', 'Form on the left, animated showcase on the right.'],
        'minimal' => ['Minimal', 'Just the form. Calm and fast.'],
    ];

    /** Editable sign-in texts with their defaults. */
    public static function loginTextDefaults(): array
    {
        return [
            'headline' => __('Run your label from one'),
            'accent' => __('focused'),
            'headline_end' => __('workspace.'),
            'subtitle' => __('Artists, releases, distribution and royalties — organised, auditable and under your control.'),
            'welcome' => __('Welcome back'),
            'note' => '',
        ];
    }

    public static function theme(): string
    {
        $theme = (string) config('labeldesk.appearance.theme', self::DEFAULT_THEME);

        return array_key_exists($theme, self::THEMES) ? $theme : self::DEFAULT_THEME;
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

    public static function font(): string
    {
        $font = (string) config('labeldesk.appearance.font', 'inter-tight');

        return array_key_exists($font, self::FONTS) ? $font : 'inter-tight';
    }

    public static function loginLayout(): string
    {
        $layout = (string) config('labeldesk.appearance.login_layout', 'spotlight');

        return array_key_exists($layout, self::LOGIN_LAYOUTS) ? $layout : 'spotlight';
    }

    /**
     * @return array<string, string>
     */
    public static function loginTexts(): array
    {
        $texts = [];
        foreach (self::loginTextDefaults() as $key => $default) {
            $value = config('labeldesk.appearance.login_'.$key);
            $texts[$key] = is_string($value) && trim($value) !== '' ? $value : $default;
        }
        // An empty note is allowed (no note).
        $texts['note'] = (string) config('labeldesk.appearance.login_note', '');

        return $texts;
    }

    public static function defaultMode(): ThemePreference
    {
        return ThemePreference::tryFrom((string) config('labeldesk.appearance.default_mode', 'dark')) ?? ThemePreference::Dark;
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

    public static function brandColor(int $which = 1): ?string
    {
        $value = (string) config('labeldesk.appearance.brand_color'.($which === 2 ? '_2' : ''), '');

        return self::validHex($value) ? strtolower($value) : null;
    }

    public static function validHex(?string $value): bool
    {
        return is_string($value) && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    /**
     * CSS that applies custom brand colours on top of the theme (printed after
     * the stylesheet). Lighter shades for dark mode are mixed in PHP so the
     * "r, g, b" tokens used for tints stay correct.
     */
    public static function themeCss(): string
    {
        $primary = self::brandColor(1);
        $secondary = self::brandColor(2);
        if ($primary === null && $secondary === null) {
            return '';
        }

        $vars = fn (string $prefix, string $hex, float $lighten) => sprintf(
            '%1$s:%2$s;%1$s-rgb:%3$s;',
            $prefix,
            self::mix($hex, '#ffffff', $lighten),
            implode(', ', self::rgb(self::mix($hex, '#ffffff', $lighten))),
        );

        $light = ($primary ? $vars('--ld-accent', $primary, 0).'--ld-accent-hover:'.self::mix($primary, '#000000', 0.12).';' : '')
            .($secondary ? $vars('--ld-accent-2', $secondary, 0) : '');
        $dark = ($primary ? $vars('--ld-accent', $primary, 0.12).'--ld-accent-hover:'.self::mix($primary, '#ffffff', 0.3).';' : '')
            .($secondary ? $vars('--ld-accent-2', $secondary, 0.1) : '');

        return "html[data-ld-theme]{{$light}}html[data-ld-theme][data-bs-theme='dark']{{$dark}}";
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);
        $mix = fn ($a, $b) => (int) round($a + ($b - $a) * $amount);

        return sprintf('#%02x%02x%02x', $mix($r1, $r2), $mix($g1, $g2), $mix($b1, $b2));
    }

    /**
     * Attributes for the <html> element and the head.
     *
     * @return array{theme: string, sidebar: string, preference: string, toggle: bool, font: string, area: string, css: string}
     */
    public static function for(?User $user): array
    {
        return [
            'theme' => self::theme(),
            'sidebar' => self::sidebar(),
            'preference' => self::preferenceFor($user),
            'toggle' => self::allowsToggle(),
            'font' => self::font(),
            'area' => $user?->isArtistAccount() ? 'artist' : 'label',
            'css' => self::themeCss(),
        ];
    }
}

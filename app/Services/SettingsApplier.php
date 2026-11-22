<?php

namespace App\Services;

/**
 * Applies database settings (Settings → General / Email) on top of the
 * .env-based configuration at boot. Runs only on installed applications.
 */
class SettingsApplier
{
    public const DATE_FORMATS = [
        'd M Y' => '20 Nov 2026',
        'M d, Y' => 'Nov 20, 2026',
        'd/m/Y' => '20/11/2026',
        'm/d/Y' => '11/20/2026',
        'Y-m-d' => '2026-11-20',
        'd.m.Y' => '20.11.2026',
    ];

    public const TIME_FORMATS = [
        'H:i' => '24-hour (14:30)',
        'h:i A' => '12-hour (02:30 PM)',
    ];

    public function __construct(protected SettingsService $settings) {}

    public function apply(): void
    {
        $s = $this->settings->all();
        if ($s === []) {
            return;
        }

        $config = [];

        if (filled($s['general.app_name'] ?? null)) {
            $config['app.name'] = $s['general.app_name'];
        }
        if (filled($s['general.timezone'] ?? null) && in_array($s['general.timezone'], timezone_identifiers_list(), true)) {
            $config['labeldesk.display_timezone'] = $s['general.timezone'];
        }
        if (filled($s['general.currency'] ?? null)) {
            $config['labeldesk.currency'] = $s['general.currency'];
        }
        if (filled($s['general.locale'] ?? null) && array_key_exists($s['general.locale'], (array) config('labeldesk.installer.locales'))) {
            $config['app.locale'] = $s['general.locale'];
            app()->setLocale($s['general.locale']);
        }
        if (array_key_exists($s['general.date_format'] ?? '', self::DATE_FORMATS)) {
            $config['labeldesk.date_format'] = $s['general.date_format'];
        }
        if (isset($config['labeldesk.date_format']) || isset($s['general.time_format'])) {
            $time = array_key_exists($s['general.time_format'] ?? '', self::TIME_FORMATS) ? $s['general.time_format'] : 'H:i';
            $config['labeldesk.datetime_format'] = ($config['labeldesk.date_format'] ?? config('labeldesk.date_format')).', '.$time;
        }

        foreach (['theme', 'modes', 'default_mode', 'sidebar', 'font', 'brand_color', 'brand_color_2', 'logo', 'logo_dark', 'favicon',
            'login_image', 'login_layout', 'login_headline', 'login_accent', 'login_headline_end', 'login_subtitle', 'login_welcome', 'login_note'] as $key) {
            if (filled($s['appearance.'.$key] ?? null)) {
                $config['labeldesk.appearance.'.$key] = (string) $s['appearance.'.$key];
            }
        }

        if (in_array($s['mail.mailer'] ?? null, ['smtp', 'sendmail', 'log'], true)) {
            $config['mail.default'] = $s['mail.mailer'];

            if ($s['mail.mailer'] === 'smtp') {
                $config['mail.mailers.smtp.host'] = $s['mail.host'] ?? null;
                $config['mail.mailers.smtp.port'] = (int) ($s['mail.port'] ?? 587);
                $config['mail.mailers.smtp.scheme'] = ($s['mail.scheme'] ?? null) === 'smtps' ? 'smtps' : null;
                $config['mail.mailers.smtp.username'] = $s['mail.username'] ?? null;
                $config['mail.mailers.smtp.password'] = $s['mail.password'] ?? null;
                $config['mail.mailers.smtp.timeout'] = 15;
            }
        }
        if (filled($s['mail.from_address'] ?? null)) {
            $config['mail.from.address'] = $s['mail.from_address'];
        }
        if (filled($s['mail.from_name'] ?? null)) {
            $config['mail.from.name'] = $s['mail.from_name'];
        }

        config($config);
    }
}

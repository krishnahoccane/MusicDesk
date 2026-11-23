<?php

/*
|--------------------------------------------------------------------------
| LabelDesk product configuration
|--------------------------------------------------------------------------
|
| Product-level settings that are not secrets. Installation-specific values
| (timezone, currency, locale) are written to .env by the installer and can
| later be overridden from Settings stored in the database.
|
*/

return [

    'product' => [
        'name' => 'LabelDesk',
        'code' => 'LABELDESK',
        'version' => '4.3.1',
        'vendor' => 'MusicDesk',
        'website' => 'https://musicdesk.tech',
    ],

    /*
     | Timestamps are always stored in UTC (config/app.php timezone). This is
     | the zone used when *displaying* dates to users.
     */
    'display_timezone' => env('LABELDESK_TIMEZONE', 'UTC'),

    'currency' => env('LABELDESK_CURRENCY', 'USD'),

    'date_format' => env('LABELDESK_DATE_FORMAT', 'd M Y'),

    'datetime_format' => env('LABELDESK_DATETIME_FORMAT', 'd M Y, H:i'),

    /*
     | Default colour mode for new users: light, dark or system.
     */
    'default_theme' => env('LABELDESK_DEFAULT_THEME', 'dark'),

    /*
     | Look of the application. Administrators change these in Settings →
     | Appearance (stored in the database, applied over these defaults).
     |   theme         aurora, ocean, emerald, sunset, rose, midnight, graphite, sand
     |   modes         both (users choose) | light | dark
     |   default_mode  system | light | dark  (when modes = both)
     |   sidebar       light | tinted | dark
     */
    /*
    | Backups (Administration → Backups). Backups are stored in "path"; the
    | "private" and "public" folders are the uploaded files that get included.
    */
    'backups' => [
        'path' => storage_path('app/private/backups'),
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],

    'appearance' => [
        'theme' => env('LABELDESK_THEME', 'musicdesk'),
        'modes' => 'both',
        'default_mode' => env('LABELDESK_DEFAULT_THEME', 'dark'),
        'sidebar' => 'light',
        'font' => 'inter-tight',
        'brand_color' => null,
        'brand_color_2' => null,
        'logo' => null,
        'logo_dark' => null,
        'favicon' => null,
        'login_image' => null,
        'login_layout' => 'spotlight',
        'login_headline' => null,
        'login_accent' => null,
        'login_headline_end' => null,
        'login_subtitle' => null,
        'login_welcome' => null,
        'login_note' => null,
    ],

    /*
     | Installation wizard. The lock file lives outside public/ and its
     | presence marks the installation as complete.
     */
    'installer' => [
        'lock_path' => env('LABELDESK_INSTALL_LOCK', storage_path('app/private/installed.lock')),
        'progress_path' => storage_path('app/private/installer/progress.json'),
        'min_php' => '8.2.0',
        'min_mysql' => '8.0.0',
        'min_mariadb' => '10.6.0',
        'currencies' => ['USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'JPY', 'CNY', 'BRL', 'MXN', 'ZAR', 'NGN', 'KES', 'AED', 'SGD', 'IDR', 'PHP', 'KRW', 'SEK', 'NOK', 'DKK', 'CHF', 'NZD', 'PKR', 'BDT', 'LKR', 'NPR', 'TRY', 'PLN'],
        'locales' => ['en' => 'English'],
    ],

    /*
     | Uploads. Files are stored on the private `local` disk (storage/app/private)
     | and only served through authorised routes. Keep these below PHP's
     | upload_max_filesize / post_max_size.
     */
    'uploads' => [
        'document_max_kb' => (int) env('LABELDESK_DOCUMENT_MAX_KB', 20480),
        'image_max_kb' => (int) env('LABELDESK_IMAGE_MAX_KB', 8192),
    ],

    /*
     | Catalog files. Artwork must be square and at least artwork_min_px wide.
     | Master audio (WAV/FLAC) can be large: PHP's upload_max_filesize and
     | post_max_size must allow it (see docs/installation.md).
     */
    'catalog' => [
        'artwork_min_px' => (int) env('LABELDESK_ARTWORK_MIN_PX', 3000),
        'artwork_max_kb' => (int) env('LABELDESK_ARTWORK_MAX_KB', 30720),
        'audio_max_kb' => (int) env('LABELDESK_AUDIO_MAX_KB', 512000),
    ],

    /*
     | Login throttling: attempts allowed per email + IP before lockout.
     */
    'login' => [
        'max_attempts' => (int) env('LABELDESK_LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('LABELDESK_LOGIN_DECAY_SECONDS', 60),
    ],

];

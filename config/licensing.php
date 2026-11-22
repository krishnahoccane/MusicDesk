<?php

/*
|--------------------------------------------------------------------------
| Licensing — MusicDesk.tech license server
|--------------------------------------------------------------------------
|
| MusicDesk.tech is the only authority that issues and validates LabelDesk
| licenses (API: /api/license/v1, see LICENSE_API_SPEC.md). LabelDesk never
| generates license keys.
|
| Drivers:
|   musicdesk  Production: HTTPS API, Ed25519-signed JWT tokens (alg EdDSA).
|   mock       Development/testing only. Refused when APP_ENV=production.
|   envato     Reserved for a future release; not available in 1.0.
|
| Tokens are verified locally with the server's PUBLIC key, so LabelDesk keeps
| working for the token's lifetime when the server cannot be reached. Without
| a valid signature nothing is ever considered licensed (fail closed).
|
*/

return [

    /*
     | Server address, issuer, signing public key and provider are NOT
     | configurable: they are constants in App\Services\License\LicenseAuthority
     | and no .env value can change them.
     */

    'product_code' => 'LABELDESK',

    // Seconds
    'connection_timeout' => 5,
    'verification_timeout' => 15,

    // Retries for connection failures / 5xx. The server allows 30 requests
    // per minute per IP, so retries are few and spaced.
    'retries' => 2,
    'retry_delay_ms' => 750,

    // Check in with the server every hour (scheduler + on page loads).
    'cache_duration_minutes' => 60,

    // Days LabelDesk keeps working after the signed token expires while the
    // server cannot be reached (tokens are valid 30 days, offline .ldlic 365).
    'offline_grace_period' => 7,

    // Expired license: read-only for this many days after the expiry date,
    // then the application is locked (no sign-in) until it is renewed.
    'expired_read_only_days' => 10,

    // "Your license expires soon" ribbon on dashboards.
    'expiring_notice_days' => 30,

    'public_support_url' => 'https://musicdesk.tech/support',
    'customer_portal_url' => 'https://musicdesk.tech/account/licenses',

    /*
     | Features known to LabelDesk.
     */
    'features' => [
        'artists' => 'Artist management',
        'releases' => 'Release management',
        'tracks' => 'Track management',
        'distribution' => 'Manual distribution',
        'royalties' => 'Royalty management',
        'documents' => 'Contracts & documents',
        'reports' => 'Reports',
        'advanced_reports' => 'Advanced reports',
        'financial_reports' => 'Advanced financial reports',
        'custom_roles' => 'Custom roles',
        'backups' => 'Backup & restore',
    ],

    /*
     | What each MusicDesk plan (the token's "plan" claim) unlocks in LabelDesk.
     | A token that carries an explicit "features" claim overrides this map.
     | "user_limit" null = unlimited users. Unknown plans get "default".
     */
    'plans' => [
        'starter' => [
            'features' => ['artists', 'releases', 'tracks', 'distribution', 'royalties', 'documents', 'reports', 'financial_reports', 'backups'],
            'user_limit' => null,
        ],
        'business' => ['features' => '*', 'user_limit' => null],
        'enterprise' => ['features' => '*', 'user_limit' => null],
        'default' => [
            'features' => ['artists', 'releases', 'tracks', 'distribution', 'royalties', 'documents', 'reports', 'financial_reports', 'backups'],
            'user_limit' => null,
        ],
    ],

];

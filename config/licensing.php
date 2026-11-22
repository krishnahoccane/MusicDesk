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

    'driver' => env('LICENSE_DRIVER', 'musicdesk'),

    'api_base_url' => rtrim((string) env('LICENSE_API_URL', 'https://musicdesk.tech/api/license/v1'), '/'),

    'endpoints' => [
        'activate' => '/activate',
        'check_in' => '/check-in',
        'deactivate' => '/deactivate',
        'public_key' => '/public-key',
    ],

    /*
     | Expected "iss" claim of license tokens (the license server's URL).
     | Leave empty to skip the issuer check.
     */
    'issuer' => rtrim((string) env('LICENSE_ISSUER', 'https://musicdesk.tech'), '/'),

    'product_code' => 'LABELDESK',

    'product_version' => '1.0.0',

    /*
     | Base64 Ed25519 public key of the MusicDesk.tech license server, shipped
     | with LabelDesk (GET /api/license/v1/public-key, kid 75338fabc5122988).
     | It is a PUBLIC key; the private key never leaves MusicDesk. Override
     | with LICENSE_PUBLIC_KEY only if MusicDesk rotates its signing key.
     */
    'public_key' => env('LICENSE_PUBLIC_KEY', 'EH9AZETQgvr7ssPdu71Z5eCpxvhDaj8jVwiopVPe+d8='),

    // Seconds
    'connection_timeout' => (int) env('LICENSE_CONNECT_TIMEOUT', 5),
    'verification_timeout' => (int) env('LICENSE_TIMEOUT', 15),

    // Retries for connection failures / 5xx. The server allows 30 requests
    // per minute per IP, so retries are few and spaced.
    'retries' => 2,
    'retry_delay_ms' => 750,

    // Check in with the server this often (hours). The server asks for 24 h.
    'cache_duration' => (int) env('LICENSE_CACHE_HOURS', 24),

    // Days LabelDesk keeps working after the signed token expires (tokens are
    // valid 30 days, offline .ldlic tokens 365 days) before becoming read-only.
    'offline_grace_period' => (int) env('LICENSE_GRACE_DAYS', 7),

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

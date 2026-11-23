<?php

namespace App\Services\License;

/**
 * The licensing authority, compiled into LabelDesk.
 *
 * Server address, token issuer, signing public key and the production
 * provider are constants: no .env value or setting can point LabelDesk at
 * another server, swap the signing key or switch to the development provider.
 *
 * The test suite may override them (only while PHPUnit runs) through the
 * config keys licensing.testing.*.
 */
final class LicenseAuthority
{
    public const API_BASE_URL = 'https://musicdesk.tech/api/license/v1';

    public const ISSUER = 'https://musicdesk.tech';

    /** Ed25519 public key of the MusicDesk.tech license server (kid 75338fabc5122988). */
    public const PUBLIC_KEY = 'EH9AZETQgvr7ssPdu71Z5eCpxvhDaj8jVwiopVPe+d8=';

    public const KEY_ID = '75338fabc5122988';

    /** Ed25519 key MusicDesk.tech signs add-on (plugin) packages with (kid 7491a41a211116b1). */
    public const PLUGIN_PUBLIC_KEY = 'ccJGVU14pvwFlkYX0R1D3amoXck0gvvwiRDGiSf65qc=';

    public const PLUGIN_KEY_ID = '7491a41a211116b1';

    /** Where administrators download add-ons. */
    public const ADDONS_URL = 'https://musicdesk.tech/account/addons';

    public const PRODUCTION_DRIVER = 'musicdesk';

    public const ENDPOINTS = [
        'activate' => '/activate',
        'check_in' => '/check-in',
        'deactivate' => '/deactivate',
        'public_key' => '/public-key',
        'updates_check' => '/updates/check',
        'updates_download' => '/updates/download',
    ];

    public static function apiBaseUrl(): string
    {
        return self::testing('api_base_url') ?? self::API_BASE_URL;
    }

    public static function pluginPublicKey(): string
    {
        return self::testing('plugin_public_key') ?? self::PLUGIN_PUBLIC_KEY;
    }

    public static function issuer(): string
    {
        return self::testing('issuer') ?? self::ISSUER;
    }

    public static function publicKey(): string
    {
        return self::testing('public_key') ?? self::PUBLIC_KEY;
    }

    public static function endpoint(string $name): string
    {
        return self::ENDPOINTS[$name] ?? '/'.ltrim($name, '/');
    }

    /**
     * Provider for new activations: always MusicDesk.tech, except in the test
     * suite and on a developer's machine (see developmentAllowed()).
     */
    public static function defaultDriver(?string $key = null): string
    {
        if (self::inTestSuite()) {
            return self::testing('driver') ?? 'mock';
        }

        // On a developer machine the mock test keys (MD-LD-TEST-…) still work;
        // real keys always go to MusicDesk.tech.
        return self::developmentAllowed() && $key !== null && str_starts_with(strtoupper($key), 'MD-LD-TEST-')
            ? 'mock'
            : self::PRODUCTION_DRIVER;
    }

    /**
     * The development provider (test keys) only runs in the test suite or under
     * `php artisan serve` / the CLI with APP_ENV=local — never under a web
     * server (Apache, LiteSpeed, nginx + PHP-FPM) that serves real visitors.
     */
    public static function developmentAllowed(): bool
    {
        if (self::inTestSuite()) {
            return true;
        }

        return app()->environment('local') && in_array(PHP_SAPI, ['cli-server', 'cli', 'phpdbg'], true);
    }

    /**
     * True only inside the PHPUnit run (PHPUNIT_COMPOSER_INSTALL is defined by the
     * phpunit binary). Unlike APP_ENV, no .env value can make this true.
     */
    public static function inTestSuite(): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__');
    }

    protected static function testing(string $key): ?string
    {
        if (! self::inTestSuite()) {
            return null;
        }

        $value = config('licensing.testing.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

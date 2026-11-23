<?php

namespace App\Plugins;

use App\Exceptions\LicenseException;
use App\Models\LicenseActivation;
use App\Models\Plugin;
use App\Services\Installation\InstallationState;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseApiClient;
use App\Services\License\LicenseAuthority;
use App\Services\License\LicenseSignatureVerifier;
use App\Services\License\Providers\MockLicenseProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Each add-on has its own license key, issued by MusicDesk.tech. The key is
 * verified with the license server for this domain, which answers with a
 * token signed by the license server's Ed25519 key:
 *
 *   POST /addons/activate  {key, addon, domain, version, php_version, instance_id} → {token, status, expires_at}
 *   POST /addons/check-in  {token, version}                                         → {token, status, expires_at}
 *   POST /addons/deactivate {token}                                                 → 204
 *
 * Token claims: iss, iat, exp, typ = "addon", addon, dom, act, lic, status (active | expiring),
 * expires (Unix or null). Contract: MusicDesk-Frontend docs/licensing.md, "Add-on API".
 * The add-on runs while its token is valid for this domain and add-on, the
 * status is usable, and the token is no older than exp + 14 days (offline grace).
 */
class AddonLicenseService
{
    public const GRACE_DAYS = 14;

    public const CHECK_MINUTES = 60;

    public const USABLE = ['active', 'expiring'];

    public function __construct(
        protected LicenseApiClient $api,
        protected InstallationState $installation,
    ) {}

    public function context(): ActivationContext
    {
        $url = LicenseActivation::query()->latest('id')->value('application_url') ?: (string) config('app.url');

        return ActivationContext::for((string) $url, (string) $this->installation->installationId());
    }

    protected function mock(?string $key): bool
    {
        return LicenseAuthority::defaultDriver($key) === 'mock';
    }

    protected function mockProvider(): MockLicenseProvider
    {
        return app(MockLicenseProvider::class);
    }

    public static function normalizeKey(string $key): string
    {
        return strtoupper(preg_replace('/\s+/', '', $key));
    }

    // ------------------------------------------------------------ server calls

    /**
     * Verify the key with MusicDesk.tech and store the signed token.
     *
     * @throws LicenseException
     */
    public function activate(Plugin $plugin, string $key): void
    {
        $key = self::normalizeKey($key);
        if (! preg_match('/^[A-Z0-9][A-Z0-9-]{6,62}[A-Z0-9]$/', $key)) {
            throw LicenseException::fromApi('invalid_key', null);
        }

        $context = $this->context();
        $data = $this->mock($key)
            ? $this->mockActivate($plugin, $key, $context)
            : $this->api->post('/addons/activate', [
                'key' => $key,
                'addon' => $plugin->addon,
                'domain' => $context->domain,
                'version' => $context->version,
                'php_version' => PHP_VERSION,
                'instance_id' => $context->installationId,
            ]);

        $this->store($plugin, $key, (string) ($data['token'] ?? ''));
    }

    /** Hourly: refresh the tokens of installed add-ons. Never throws. */
    public function refreshDue(): void
    {
        $due = Plugin::query()->whereNotNull('license_token')
            ->where(fn ($q) => $q->whereNull('license_checked_at')->orWhere('license_checked_at', '<=', now()->subMinutes(self::CHECK_MINUTES)))
            ->get();

        foreach ($due as $plugin) {
            try {
                $this->checkIn($plugin);
            } catch (Throwable $e) {
                Log::info('Add-on license check failed', ['plugin' => $plugin->slug, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @throws LicenseException */
    public function checkIn(Plugin $plugin): void
    {
        $plugin->forceFill(['license_checked_at' => now()])->save();
        $key = (string) $plugin->license_key;

        try {
            $data = $this->mock($key)
                ? $this->mockCheckIn($plugin, $key)
                : $this->api->post('/addons/check-in', ['token' => (string) $plugin->license_token, 'version' => $this->context()->version]);
            $this->store($plugin, $key, (string) ($data['token'] ?? ''));
        } catch (LicenseException $e) {
            $plugin->forceFill(['license_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            // A definitive answer stops the add-on; network problems keep the token (offline grace).
            if (! $e->retryable && (str_starts_with($e->errorCode, 'license_') || in_array($e->errorCode, ['activation_deactivated', 'activation_not_found', 'invalid_key', 'invalid_token', 'domain_blocked', 'addon_mismatch', 'addon_domain_not_licensed'], true))) {
                $plugin->forceFill(['license_status' => str_starts_with($e->errorCode, 'license_') ? substr($e->errorCode, 8) : $e->errorCode])->save();
            }
            throw $e;
        }
    }

    /** Free this domain on the add-on key (best effort) and forget the key. */
    public function release(Plugin $plugin): void
    {
        $key = (string) $plugin->license_key;
        if ($plugin->license_token && ! $this->mock($key)) {
            rescue(fn () => $this->api->post('/addons/deactivate', ['token' => (string) $plugin->license_token]), report: false);
        }

        $plugin->forceFill([
            'license_key' => null, 'license_token' => null, 'license_status' => null,
            'license_expires_at' => null, 'license_checked_at' => null, 'license_error' => null,
        ])->save();
    }

    // ------------------------------------------------------------ verification

    /** Whether the add-on may run now (offline check of the stored signed token). */
    public function valid(Plugin $plugin): bool
    {
        if (! $plugin->license_token) {
            return false;
        }

        try {
            $claims = $this->verify((string) $plugin->license_token, $plugin, (string) $plugin->license_key);
        } catch (Throwable) {
            return false;
        }

        if (! in_array($plugin->license_status ?? ($claims['status'] ?? null), self::USABLE, true)) {
            return false;
        }
        if (isset($claims['expires']) && is_numeric($claims['expires']) && (int) $claims['expires'] <= time()) {
            return false;
        }

        return (int) ($claims['exp'] ?? 0) + self::GRACE_DAYS * 86400 > time();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LicenseException
     */
    public function verify(string $token, Plugin $plugin, ?string $key): array
    {
        [$payload, $signature] = LicenseSignatureVerifier::split($token);
        $parts = explode('.', $payload);
        $header = json_decode((string) LicenseSignatureVerifier::unb64($parts[0] ?? ''), true);
        $claims = json_decode((string) LicenseSignatureVerifier::unb64($parts[1] ?? ''), true);
        $sig = LicenseSignatureVerifier::unb64($signature);
        $public = base64_decode((string) ($this->mock($key) ? $this->mockProvider()->publicKey() : LicenseAuthority::publicKey()), true);

        if (count($parts) !== 2 || ! is_array($header) || ($header['alg'] ?? null) !== 'EdDSA' || ! is_array($claims)
            || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($sig, $payload, $public)) {
            throw LicenseException::invalidSignature();
        }

        if (($claims['typ'] ?? null) !== 'addon' || ($claims['addon'] ?? null) !== $plugin->addon) {
            throw new LicenseException('addon_mismatch', __('This key is not for the :name add-on.', ['name' => $plugin->name]));
        }
        if (($claims['iss'] ?? null) !== LicenseAuthority::issuer()) {
            throw LicenseException::invalidSignature();
        }
        if (strtolower((string) ($claims['dom'] ?? '')) !== $this->context()->domain) {
            throw LicenseException::fromApi('domain_mismatch', null);
        }

        return $claims;
    }

    /** @throws LicenseException */
    protected function store(Plugin $plugin, string $key, string $token): void
    {
        if ($token === '') {
            throw LicenseException::fromApi('invalid_token', null);
        }
        $claims = $this->verify($token, $plugin, $key);

        $plugin->forceFill([
            'license_key' => $key,
            'license_token' => $token,
            'license_status' => (string) ($claims['status'] ?? 'active'),
            'license_expires_at' => isset($claims['expires']) && is_numeric($claims['expires']) ? CarbonImmutable::createFromTimestamp((int) $claims['expires']) : null,
            'license_checked_at' => now(),
            'license_error' => null,
        ])->save();
    }

    // ------------------------------------------------------------ development / tests (MD-LD-TEST-… keys)

    /** @return array<string, mixed> */
    protected function mockActivate(Plugin $plugin, string $key, ActivationContext $context): array
    {
        if (! preg_match('/^MD-LD-TEST-([A-Z0-9]{4})-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key, $m)) {
            throw LicenseException::fromApi('invalid_key', null);
        }
        match ($m[1]) {
            'EXPD' => throw LicenseException::fromApi('license_expired', null),
            'SUSP' => throw LicenseException::fromApi('license_suspended', null),
            'REVK' => throw LicenseException::fromApi('license_revoked', null),
            default => null,
        };

        return ['token' => $this->mockToken($plugin, $context->domain, $key)];
    }

    /** @return array<string, mixed> */
    protected function mockCheckIn(Plugin $plugin, string $key): array
    {
        return ['token' => $this->mockToken($plugin, $this->context()->domain, $key)];
    }

    protected function mockToken(Plugin $plugin, string $domain, string $key): string
    {
        return $this->mockProvider()->sign([
            'typ' => 'addon',
            'addon' => $plugin->addon,
            'dom' => $domain,
            'act' => 'addon-act-'.substr(hash('sha256', $domain.'|'.$key), 0, 16),
            'status' => 'active',
            'expires' => null,
        ], 30);
    }
}

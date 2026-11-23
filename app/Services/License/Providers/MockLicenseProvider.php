<?php

namespace App\Services\License\Providers;

use App\Exceptions\LicenseException;
use App\Services\License\Contracts\LicenseProviderInterface;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use App\Services\License\LicenseAuthority;
use App\Services\License\LicenseSignatureVerifier;
use Illuminate\Support\Facades\File;

/**
 * DEVELOPMENT AND TEST ONLY — simulates the MusicDesk.tech license server.
 *
 * Refuses to operate when APP_ENV=production, so it can never activate a real
 * customer installation. It issues EdDSA JWTs in exactly the server's format,
 * signed with a throw-away key pair generated on this machine, and they go
 * through the same verification as production tokens.
 *
 * Test keys (format MD-LD-TEST-<CODE>-XXXX-XXXX):
 *   ACTV  active "business" license, all features, 25 users
 *   STRT  active "starter" license (no royalties/documents/advanced), 3 users
 *   PEND  pending payment (usable)        EXPD  expired (403 license_expired)
 *   SUSP  suspended                       REVK  revoked
 *   LIMT  domain limit reached            anything else: invalid_key
 */
class MockLicenseProvider implements LicenseProviderInterface
{
    public const PLANS = [
        'ACTV' => ['plan' => 'business', 'status' => 'active', 'features' => ['artists', 'releases', 'tracks', 'distribution', 'royalties', 'documents', 'reports', 'advanced_reports', 'financial_reports', 'custom_roles', 'backups'], 'user_limit' => 25],
        'STRT' => ['plan' => 'starter', 'status' => 'active', 'features' => ['artists', 'releases', 'tracks', 'distribution', 'reports', 'backups'], 'user_limit' => 3],
        'PEND' => ['plan' => 'starter', 'status' => 'pending', 'features' => ['artists', 'releases', 'tracks', 'distribution', 'reports', 'backups'], 'user_limit' => 3],
    ];

    /** @var array<string, bool> deactivated activation ids */
    protected static array $deactivated = [];

    public function __construct(protected LicenseSignatureVerifier $verifier) {}

    public function name(): string
    {
        return 'mock';
    }

    public function salesChannel(): string
    {
        return 'musicdesk_direct';
    }

    public function publicKey(): ?string
    {
        return base64_encode(sodium_crypto_sign_publickey($this->keypair()));
    }

    public function activate(ActivationContext $context, string $licenseKey): LicenseResult
    {
        $this->guard();

        if (! preg_match('/^MD-LD-TEST-([A-Z0-9]{4})-[A-Z0-9]{4}-[A-Z0-9]{4}$/', strtoupper(trim($licenseKey)), $m)) {
            throw LicenseException::fromApi('invalid_key', null);
        }

        $code = $m[1];
        match ($code) {
            'EXPD' => throw LicenseException::fromApi('license_expired', null),
            'SUSP' => throw LicenseException::fromApi('license_suspended', null),
            'REVK' => throw LicenseException::fromApi('license_revoked', null),
            'LIMT' => throw LicenseException::fromApi('domain_limit_reached', null),
            default => null,
        };

        $plan = self::PLANS[$code] ?? throw LicenseException::fromApi('invalid_key', null);
        $activationId = 'act-'.substr(hash('sha256', $context->domain.'|'.$licenseKey), 0, 24);
        unset(static::$deactivated[$activationId]);

        return $this->issue($context, $activationId, $plan, 30);
    }

    public function checkIn(ActivationContext $context, string $token): LicenseResult
    {
        $this->guard();
        [$payload, $signature] = LicenseSignatureVerifier::split($token);
        $current = $this->verifier->verify($payload, $signature, $this->publicKey(), $context, allowExpiredToken: true);

        if (isset(static::$deactivated[(string) $current->activationId])) {
            throw LicenseException::fromApi('activation_deactivated', null);
        }

        $plan = ['plan' => $current->plan, 'status' => $current->status, 'features' => $current->features, 'user_limit' => $current->userLimit, 'expires' => $current->expiresAt?->timestamp];

        return $this->issue($context, (string) $current->activationId, $plan, 30);
    }

    /** Development code for manual deactivation (no email is sent by the mock). */
    public const DEACTIVATION_CODE = '246810';

    public function requestDeactivationCode(ActivationContext $context, string $token): array
    {
        $this->guard();
        [$payload, $signature] = LicenseSignatureVerifier::split($token);
        $this->verifier->verify($payload, $signature, $this->publicKey(), $context, allowExpiredToken: true);

        return ['sent_to' => 'o•••@example.com', 'expires_in' => 600];
    }

    public function deactivate(ActivationContext $context, string $token, ?string $code = null, string $reason = 'manual'): void
    {
        $this->guard();
        [$payload, $signature] = LicenseSignatureVerifier::split($token);
        $current = $this->verifier->verify($payload, $signature, $this->publicKey(), $context, allowExpiredToken: true);
        if ($reason === 'manual' && $code !== self::DEACTIVATION_CODE) {
            throw LicenseException::fromApi('invalid_code', null);
        }
        static::$deactivated[(string) $current->activationId] = true;
    }

    /**
     * Signs a token exactly like the MusicDesk server (also used for offline files in tests).
     *
     * @param  array<string, mixed>  $plan
     */
    public function issue(ActivationContext $context, string $activationId, array $plan, int $days, ?int $issuedAt = null): LicenseResult
    {
        $token = $this->sign([
            'lic' => 'lic-mock',
            'act' => $activationId,
            'dom' => $context->domain,
            'env' => $context->isStaging() ? 'staging' : 'production',
            'plan' => $plan['plan'],
            'status' => $plan['status'],
            'support_until' => now()->addYear()->timestamp,
            'max_version' => null,
            'expires' => $plan['expires'] ?? null,
            'features' => $plan['features'],
            'user_limit' => $plan['user_limit'],
        ], $days, $issuedAt);

        [$payload, $signature] = LicenseSignatureVerifier::split($token);

        return $this->verifier->verify($payload, $signature, $this->publicKey(), $context, [
            'messages' => $plan['status'] === 'pending' ? ['Awaiting payment (development mock).'] : [],
            'check_in_requested' => false,
        ], allowExpiredToken: true);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims, int $days, ?int $issuedAt = null): string
    {
        $this->guard();
        $now = $issuedAt ?? now()->timestamp;
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'mock'];
        $claims += ['iss' => LicenseAuthority::issuer(), 'iat' => $now, 'exp' => $now + $days * 86400];

        $input = LicenseSignatureVerifier::b64((string) json_encode($header)).'.'.LicenseSignatureVerifier::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $input.'.'.LicenseSignatureVerifier::b64(sodium_crypto_sign_detached($input, sodium_crypto_sign_secretkey($this->keypair())));
    }

    protected function guard(): void
    {
        if (app()->isProduction()) {
            throw new LicenseException('mock_forbidden', __('The development license provider cannot be used in production.'));
        }
    }

    protected function keypair(): string
    {
        $this->guard();

        $path = storage_path('app/private/licensing/mock-signing.keypair');

        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, base64_encode(sodium_crypto_sign_keypair()));
        }

        return (string) base64_decode((string) File::get($path));
    }
}

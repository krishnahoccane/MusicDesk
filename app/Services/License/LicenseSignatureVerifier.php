<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Verifies license tokens issued by MusicDesk.tech and turns them into
 * trusted LicenseResult objects.
 *
 * Tokens are compact JWTs signed with Ed25519 (header alg "EdDSA"):
 *   base64url(header) . base64url(claims) . base64url(signature)
 * Claims: iss, iat, exp, lic, act, dom, env, plan, status, support_until, max_version,
 * trial and limits (free trials, MusicDesk.tech 2.0.1).
 *
 * Only the PUBLIC key is present in LabelDesk, so editing local data can never
 * produce a valid token.
 */
class LicenseSignatureVerifier
{
    /**
     * Splits a compact JWT into [signing input, signature] as stored.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $token): array
    {
        $token = trim($token);
        $last = strrpos($token, '.');
        if ($last === false || substr_count($token, '.') !== 2) {
            throw LicenseException::invalidSignature();
        }

        return [substr($token, 0, $last), substr($token, $last + 1)];
    }

    /**
     * @param  string  $payload  JWT signing input "header.claims"
     * @param  string  $signature  base64url Ed25519 signature
     * @param  array<string, mixed>  $envelope  Unsigned response fields (messages, check_in_requested)
     */
    public function verify(
        string $payload,
        string $signature,
        ?string $publicKey,
        ActivationContext $context,
        array $envelope = [],
        bool $allowExpiredToken = false,
    ): LicenseResult {
        $key = $publicKey ? base64_decode($publicKey, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw LicenseException::notConfigured();
        }

        $parts = explode('.', $payload);
        $sig = self::unb64($signature);
        if (count($parts) !== 2 || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw LicenseException::invalidSignature();
        }

        $header = json_decode((string) self::unb64($parts[0]), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'EdDSA') {
            throw LicenseException::invalidSignature();
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($sig, $payload, $key);
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw LicenseException::invalidSignature();
        }

        $claims = json_decode((string) self::unb64($parts[1]), true);
        if (! is_array($claims) || ! is_int($claims['exp'] ?? null) || ! is_int($claims['iat'] ?? null)) {
            throw LicenseException::invalidSignature();
        }

        $issuer = LicenseAuthority::issuer();
        if ($issuer !== '' && rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer) {
            throw new LicenseException('wrong_issuer', __('The license token was not issued by MusicDesk.tech.'));
        }

        // The signed token must describe THIS installation's domain.
        if (ActivationContext::normalizeDomain((string) ($claims['dom'] ?? '')) !== $context->domain) {
            throw LicenseException::fromApi('domain_mismatch', null);
        }

        $validUntil = CarbonImmutable::createFromTimestampUTC($claims['exp']);
        $issuedAt = CarbonImmutable::createFromTimestampUTC($claims['iat']);

        if (! $allowExpiredToken && $validUntil->isPast()) {
            throw LicenseException::fromApi('token_expired', null);
        }

        $plan = strtolower((string) ($claims['plan'] ?? 'default')) ?: 'default';
        [$features, $userLimit] = $this->entitlements($plan, $claims);
        $supportUntil = $this->timestamp($claims['support_until'] ?? null);

        $messages = array_values(array_map(
            fn ($m) => mb_substr(strip_tags((string) $m), 0, 500),
            array_filter((array) ($envelope['messages'] ?? []), 'is_scalar'),
        ));

        return new LicenseResult(
            status: strtolower((string) ($claims['status'] ?? 'unknown')),
            plan: $plan,
            licenseType: null,
            salesChannel: 'musicdesk_direct',
            features: $features,
            expiresAt: $this->timestamp($claims['expires'] ?? null), // signed license expiry (null = lifetime)
            activationLimit: null,
            activationsUsed: null,
            userLimit: $userLimit,
            supportUntil: $supportUntil,
            updatesUntil: $supportUntil,
            activationId: isset($claims['act']) ? (string) $claims['act'] : null,
            activationSecret: null,
            tokenPayload: $payload,
            tokenSignature: $signature,
            issuedAt: $issuedAt,
            validUntil: $validUntil,
            payload: $claims,
            message: $messages !== [] ? implode(' ', $messages) : (isset($envelope['message']) ? strip_tags((string) $envelope['message']) : null),
            maxVersion: isset($claims['max_version']) && $claims['max_version'] !== '' ? (string) $claims['max_version'] : null,
            messages: $messages,
            checkInRequested: (bool) ($envelope['check_in_requested'] ?? false),
            environment: isset($claims['env']) ? (string) $claims['env'] : null,
            trial: ($claims['trial'] ?? false) === true,
            limits: ($claims['trial'] ?? false) === true ? self::limits($claims['limits'] ?? null) : null,
        );
    }

    /**
     * Trial limits from the signed token. Missing or malformed numbers count as "no limit"
     * (the server enforces domains itself); add-ons only when the claim says so.
     *
     * @return array{domains: ?int, users: ?int, artists: ?int, releases: ?int, tracks: ?int, addons: bool}
     */
    public static function limits(mixed $limits): array
    {
        $limits = is_array($limits) ? $limits : [];
        $count = fn (string $key) => isset($limits[$key]) && is_int($limits[$key]) && $limits[$key] >= 0 ? $limits[$key] : null;

        return [
            'domains' => $count('domains'),
            'users' => $count('users'),
            'artists' => $count('artists'),
            'releases' => $count('releases'),
            'tracks' => $count('tracks'),
            'addons' => ($limits['addons'] ?? false) === true,
        ];
    }

    /**
     * Features and user limit: an explicit signed "features" claim wins;
     * otherwise the plan map in config/licensing.php decides.
     *
     * @param  array<string, mixed>  $claims
     * @return array{0: list<string>, 1: ?int}
     */
    protected function entitlements(string $plan, array $claims): array
    {
        $known = array_keys((array) config('licensing.features'));

        if (isset($claims['features']) && is_array($claims['features'])) {
            $features = array_values(array_intersect(array_filter($claims['features'], 'is_string'), $known));
            $limit = isset($claims['user_limit']) && is_int($claims['user_limit']) ? $claims['user_limit'] : null;

            return [$features, $limit];
        }

        $plans = (array) config('licensing.plans');
        $definition = $plans[$plan] ?? $plans['default'] ?? ['features' => [], 'user_limit' => null];
        $features = $definition['features'] === '*' ? $known : array_values(array_intersect((array) $definition['features'], $known));

        return [$features, isset($definition['user_limit']) ? (int) $definition['user_limit'] : null];
    }

    protected function timestamp(mixed $value): ?CarbonImmutable
    {
        if (is_int($value)) {
            return CarbonImmutable::createFromTimestampUTC($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}

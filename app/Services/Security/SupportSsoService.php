<?php

namespace App\Services\Security;

use App\Enums\SystemRole;
use App\Exceptions\SupportSsoException;
use App\Models\User;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseAuthority;
use App\Services\License\LicenseSignatureVerifier;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * MusicDesk support sign-in (MusicDesk.tech 2.0.2). MusicDesk's support team can open a
 * customer's LabelDesk as its Super Admin to help with a problem. The one-time token is signed
 * by MusicDesk.tech with the licensing key and verified OFFLINE with the public key LabelDesk
 * already ships, so it works on any domain — even one whose licence is locked or missing.
 *
 * It is NOT a license token: the `typ` must be "admin_sso", and a licence/add-on token
 * (`typ` "addon"/absent) can never sign anyone in. Every use is single-use, short-lived,
 * audited and e-mailed to the Super Admin, and the whole feature can be switched off.
 */
class SupportSsoService
{
    /** Session length of a support sign-in. */
    public const SESSION_MINUTES = 60;

    /** Token lifetime the issuer uses (2 minutes) plus the allowed clock skew. */
    protected const MAX_LIFETIME = 120;

    protected const SKEW = 30;

    public function __construct(protected SettingsService $settings) {}

    /** The customer can turn support sign-in off (Settings → Security); on by default. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('security.support_sso', true);
    }

    public function emailsNotice(): bool
    {
        return (bool) $this->settings->get('security.support_sso_email', true);
    }

    /**
     * Verify a support token and return its claims, or throw. The reason on the exception is
     * for the audit log only; the message shown to the browser is always generic.
     *
     * @return array<string, mixed>
     *
     * @throws SupportSsoException
     */
    public function verify(string $token): array
    {
        $token = trim($token);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new SupportSsoException('malformed');
        }
        [$headerB64, $claimsB64, $sigB64] = $parts;

        $header = json_decode((string) LicenseSignatureVerifier::unb64($headerB64), true);
        $claims = json_decode((string) LicenseSignatureVerifier::unb64($claimsB64), true);
        $sig = LicenseSignatureVerifier::unb64($sigB64);
        $public = base64_decode((string) LicenseAuthority::publicKey(), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'EdDSA' || ! is_array($claims)
            || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || $public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($sig, $headerB64.'.'.$claimsB64, $public)) {
            throw new SupportSsoException('bad_signature');
        }

        // The type check is what separates a support token from a licence token.
        if (($claims['typ'] ?? null) !== 'admin_sso') {
            throw new SupportSsoException('wrong_type');
        }
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== LicenseAuthority::issuer()) {
            throw new SupportSsoException('wrong_issuer');
        }

        $domain = ActivationContext::for((string) config('app.url'), '')->domain;
        $audience = ActivationContext::normalizeDomain((string) ($claims['aud'] ?? $claims['dom'] ?? ''));
        if ($audience === '' || ! hash_equals($domain, $audience)) {
            throw new SupportSsoException('wrong_domain');
        }

        $now = now()->timestamp;
        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        if ($iat <= 0 || $exp <= 0 || $iat > $now + self::SKEW || $exp < $now - self::SKEW || $exp - $iat > self::MAX_LIFETIME + self::SKEW) {
            throw new SupportSsoException('expired');
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '' || ! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $jti)) {
            throw new SupportSsoException('bad_jti');
        }
        // Single use: remember the id until the token would have expired anyway. add() is atomic
        // and shared across workers (database/redis cache), so a replay loses the race.
        if (! Cache::add($this->jtiKey($jti), $now, max(1, $exp - $now + self::SKEW))) {
            throw new SupportSsoException('replayed');
        }

        return $claims;
    }

    /** The active Super Admin to sign in as: the first one, by id. */
    public function supportAdmin(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', SystemRole::SuperAdmin->value))
            ->orderBy('id')
            ->first();
    }

    protected function jtiKey(string $jti): string
    {
        return 'musicdesk-sso-jti:'.hash('sha256', $jti);
    }
}

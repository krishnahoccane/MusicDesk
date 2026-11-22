<?php

namespace LabelDesk\Addons\Sso\Oidc;

/**
 * Validates an OpenID Connect ID token (OIDC Core §3.1.3.7): the signature
 * against the provider's published keys (RS256/384/512, ES256/384/512 via
 * OpenSSL), issuer, audience, authorised party, expiry and nonce.
 * Symmetric ("HS…") and unsigned ("none") tokens are always refused.
 */
class IdTokenVerifier
{
    public const LEEWAY = 120;

    /** @var array<string, array{type: string, algo: int, curve?: string}> */
    public const ALGORITHMS = [
        'RS256' => ['type' => 'RSA', 'algo' => OPENSSL_ALGO_SHA256],
        'RS384' => ['type' => 'RSA', 'algo' => OPENSSL_ALGO_SHA384],
        'RS512' => ['type' => 'RSA', 'algo' => OPENSSL_ALGO_SHA512],
        'ES256' => ['type' => 'EC', 'algo' => OPENSSL_ALGO_SHA256, 'curve' => 'P-256'],
        'ES384' => ['type' => 'EC', 'algo' => OPENSSL_ALGO_SHA384, 'curve' => 'P-384'],
        'ES512' => ['type' => 'EC', 'algo' => OPENSSL_ALGO_SHA512, 'curve' => 'P-521'],
    ];

    /**
     * @param  callable(bool): array<int, mixed>  $keys  the provider's JWKS keys; called again with true when no key matches (key rotation)
     * @param  list<string>|null  $allowedAlgorithms  from discovery (id_token_signing_alg_values_supported)
     * @return array<string, mixed> the verified claims
     */
    public function verify(string $jwt, callable $keys, string $issuer, string $clientId, string $nonce, ?array $allowedAlgorithms = null, ?int $now = null): array
    {
        $now ??= time();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw $this->invalid('malformed');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(Jwk::b64($h), true);
        $claims = json_decode(Jwk::b64($p), true);
        $signature = Jwk::b64($s);
        if (! is_array($header) || ! is_array($claims) || $signature === '') {
            throw $this->invalid('malformed');
        }

        $alg = is_string($header['alg'] ?? null) ? $header['alg'] : '';
        $spec = self::ALGORITHMS[$alg] ?? null;
        if ($spec === null || ($allowedAlgorithms !== null && ! in_array($alg, $allowedAlgorithms, true))) {
            throw new SsoException(__('The identity provider signed the sign-in with an algorithm LabelDesk does not accept (:alg).', ['alg' => mb_substr($alg, 0, 12)]), 'bad_algorithm');
        }

        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
        $jwk = $this->findKey($keys(false), $spec, $kid) ?? $this->findKey($keys(true), $spec, $kid);
        if ($jwk === null) {
            throw new SsoException(__('The identity provider signed the sign-in with an unknown key.'), 'unknown_key');
        }

        if ($spec['type'] === 'EC') {
            $signature = Jwk::ecdsaToDer($signature, Jwk::CURVES[$spec['curve']]['size']);
        }
        $key = openssl_pkey_get_public(Jwk::toPem($jwk));
        $valid = $key !== false && openssl_verify($h.'.'.$p, $signature, $key, $spec['algo']) === 1;
        while (openssl_error_string() !== false) {
            // clear the OpenSSL error queue
        }
        if (! $valid) {
            throw new SsoException(__('The sign-in token signature is not valid.'), 'bad_signature');
        }

        $this->checkClaims($claims, $issuer, $clientId, $nonce, $now);

        return $claims;
    }

    /** @param  array<string, mixed>  $claims */
    protected function checkClaims(array $claims, string $issuer, string $clientId, string $nonce, int $now): void
    {
        if (! is_string($claims['iss'] ?? null) || rtrim($claims['iss'], '/') !== rtrim($issuer, '/')) {
            throw $this->invalid('issuer');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($clientId, $audiences, true)) {
            throw $this->invalid('audience');
        }
        if (isset($claims['azp']) && $claims['azp'] !== $clientId) {
            throw $this->invalid('azp');
        }
        if (count($audiences) > 1 && ! isset($claims['azp'])) {
            throw $this->invalid('azp');
        }

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - self::LEEWAY) {
            throw new SsoException(__('The sign-in has expired. Please try again.'), 'expired');
        }
        if (! is_numeric($claims['iat'] ?? null) || (int) $claims['iat'] > $now + self::LEEWAY) {
            throw $this->invalid('iat');
        }
        if (isset($claims['nbf']) && (! is_numeric($claims['nbf']) || (int) $claims['nbf'] > $now + self::LEEWAY)) {
            throw $this->invalid('nbf');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw $this->invalid('nonce');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw $this->invalid('subject');
        }
    }

    /**
     * @param  array<int, mixed>  $keys
     * @param  array{type: string, algo: int, curve?: string}  $spec
     * @return array<string, mixed>|null
     */
    protected function findKey(array $keys, array $spec, ?string $kid): ?array
    {
        $candidates = array_values(array_filter($keys, function ($k) use ($spec, $kid) {
            return is_array($k)
                && ($k['kty'] ?? null) === $spec['type']
                && ($k['use'] ?? 'sig') === 'sig'
                && (! isset($spec['curve']) || ($k['crv'] ?? null) === $spec['curve'])
                && ($kid === null || ($k['kid'] ?? null) === $kid);
        }));

        // Without a "kid" the key must be unambiguous.
        if (count($candidates) === 1 || ($kid !== null && $candidates !== [])) {
            return $candidates[0];
        }

        return null;
    }

    protected function invalid(string $reason): SsoException
    {
        return new SsoException(__('The identity provider returned a sign-in token LabelDesk cannot accept.'), 'invalid_token_'.$reason);
    }
}

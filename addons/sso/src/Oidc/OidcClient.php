<?php

namespace LabelDesk\Addons\Sso\Oidc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LabelDesk\Addons\Sso\Models\SsoProvider;

/**
 * OpenID Connect authorization code flow with PKCE (S256), state and nonce:
 * provider discovery, the authorization URL, the code exchange and ID-token
 * verification. Discovery documents and key sets are cached for an hour.
 */
class OidcClient
{
    public const CACHE_SECONDS = 3600;

    public const TIMEOUT = 15;

    public function __construct(protected IdTokenVerifier $verifier) {}

    /**
     * The provider's discovery document, checked against its configured issuer.
     *
     * @return array<string, mixed>
     */
    public function discover(SsoProvider $provider, bool $fresh = false): array
    {
        $key = 'sso:discovery:'.sha1($provider->issuer);
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $document = $this->getJson($provider->discoveryUrl(), 'discovery');
        $this->checkDiscovery($provider, $document);
        Cache::put($key, $document, self::CACHE_SECONDS);

        return $document;
    }

    /** @param  array<string, mixed>  $document */
    protected function checkDiscovery(SsoProvider $provider, array $document): void
    {
        if (! is_string($document['issuer'] ?? null) || rtrim($document['issuer'], '/') !== rtrim($provider->issuer, '/')) {
            throw new SsoException(__('The identity provider reports a different issuer than configured (:issuer).', [
                'issuer' => mb_substr((string) ($document['issuer'] ?? '—'), 0, 120),
            ]), 'discovery_issuer');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (! is_string($document[$field] ?? null) || ! str_starts_with($document[$field], 'https://')) {
                throw new SsoException(__('The identity provider configuration is missing a secure :field.', ['field' => $field]), 'discovery_incomplete');
            }
        }
        if (isset($document['response_types_supported']) && ! in_array('code', (array) $document['response_types_supported'], true)) {
            throw new SsoException(__('The identity provider does not support the authorization code flow.'), 'discovery_incomplete');
        }
    }

    /**
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function authorizationRequest(SsoProvider $provider, string $redirectUri): array
    {
        $document = $this->discover($provider);
        $state = bin2hex(random_bytes(20));
        $nonce = bin2hex(random_bytes(20));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $params = [
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $provider->scopeList()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        // Google Workspace: show only accounts of the allowed domain.
        $domains = $provider->domains();
        if ($provider->preset === 'google' && count($domains) === 1) {
            $params['hd'] = $domains[0];
        }

        $endpoint = (string) $document['authorization_endpoint'];
        $url = $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return compact('url', 'state', 'nonce', 'verifier');
    }

    /**
     * Exchange the authorization code and return the verified ID-token claims.
     *
     * @return array<string, mixed>
     */
    public function exchange(SsoProvider $provider, string $code, string $verifier, string $nonce, string $redirectUri): array
    {
        $document = $this->discover($provider);
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'client_id' => $provider->client_id,
        ];

        $methods = (array) ($document['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
        $request = Http::asForm()->acceptJson()->timeout(self::TIMEOUT);
        if (in_array('client_secret_basic', $methods, true)) {
            $request = $request->withBasicAuth(rawurlencode($provider->client_id), rawurlencode($provider->client_secret));
        } elseif (in_array('client_secret_post', $methods, true)) {
            $form['client_secret'] = $provider->client_secret;
        } else {
            throw new SsoException(__('The identity provider does not accept a client secret.'), 'token_auth_method');
        }

        try {
            $response = $request->post((string) $document['token_endpoint'], $form);
        } catch (ConnectionException) {
            throw new SsoException(__('The identity provider could not be reached. Please try again.'), 'token_unreachable');
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? preg_replace('/[^a-z_]/', '', mb_substr($body['error'], 0, 40)) : 'http_'.$response->status();
            throw new SsoException(__('The identity provider refused the sign-in (:error).', ['error' => $error]), 'token_refused');
        }
        if (! is_string($body['id_token'] ?? null)) {
            throw new SsoException(__('The identity provider did not return an ID token. Check that the "openid" scope is allowed.'), 'token_missing');
        }

        $allowed = isset($document['id_token_signing_alg_values_supported']) ? array_values((array) $document['id_token_signing_alg_values_supported']) : null;

        return $this->verifier->verify(
            $body['id_token'],
            fn (bool $refresh) => $this->keys((string) $document['jwks_uri'], $refresh),
            $provider->issuer,
            $provider->client_id,
            $nonce,
            $allowed,
        );
    }

    /**
     * The provider's signing keys. A refresh (unknown key id, e.g. after key
     * rotation) re-downloads them at most once a minute.
     *
     * @return array<int, mixed>
     */
    public function keys(string $jwksUri, bool $refresh = false): array
    {
        $key = 'sso:jwks:'.sha1($jwksUri);
        $cached = Cache::get($key);
        if (is_array($cached) && (! $refresh || ($cached['at'] ?? 0) > now()->getTimestamp() - 60)) {
            return $cached['keys'];
        }

        $document = $this->getJson($jwksUri, 'jwks');
        $keys = is_array($document['keys'] ?? null) ? array_values($document['keys']) : [];
        Cache::put($key, ['keys' => $keys, 'at' => now()->getTimestamp()], self::CACHE_SECONDS);

        return $keys;
    }

    /** @return array<string, mixed> */
    protected function getJson(string $url, string $what): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new SsoException(__('Identity provider addresses must use https://.'), $what.'_insecure');
        }
        try {
            $response = Http::acceptJson()->timeout(self::TIMEOUT)->get($url);
        } catch (ConnectionException) {
            throw new SsoException(__('The identity provider could not be reached. Please try again.'), $what.'_unreachable');
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new SsoException(__('The identity provider configuration could not be loaded (HTTP :status).', ['status' => $response->status()]), $what.'_failed');
        }

        return $json;
    }

    /**
     * The verified email address in the claims, or null when the provider
     * does not vouch for it ("email_verified", or Entra's "xms_edov").
     *
     * @param  array<string, mixed>  $claims
     */
    public static function verifiedEmail(array $claims): ?string
    {
        $email = $claims['email'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return null;
        }
        $truthy = fn ($v) => $v === true || $v === 'true' || $v === 1 || $v === '1';
        if (! $truthy($claims['email_verified'] ?? null) && ! $truthy($claims['xms_edov'] ?? null)) {
            return null;
        }

        return mb_strtolower($email);
    }
}

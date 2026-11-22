<?php

namespace LabelDesk\Addons\Sso\Tests;

use App\Enums\SystemRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use LabelDesk\Addons\Sso\Models\SsoProvider;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

/**
 * Installs the SSO add-on from source and fakes an OpenID Connect provider
 * at https://idp.example.test (discovery, JWKS, token endpoint) that signs
 * ID tokens with the throw-away test keys in tests/keys.
 */
abstract class SsoTestCase extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    public const ISSUER = 'https://idp.example.test';

    protected User $admin;

    /** Claims merged into the next ID token the fake provider issues. */
    protected array $tokenClaims = [];

    protected string $tokenAlg = 'RS256';

    protected string $tokenKid = 'rsa-1';

    protected ?string $tokenOverride = null;

    /** @var list<Request> */
    protected array $tokenRequests = [];

    protected int $jwksFetches = 0;

    /** @var list<array<string, mixed>> */
    protected array $jwks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin, ['email' => 'admin@label.test']);
        $this->activateLicense();
        $this->installAddon(dirname(__DIR__));

        $this->jwks = [$this->rsaJwk('rsa.pem', 'rsa-1'), $this->ecJwk('ec.pem', 'ec-1')];
        $this->fakeIdp();
    }

    protected function fakeIdp(array $discovery = []): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => fn () => Http::response(array_merge([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'jwks_uri' => self::ISSUER.'/jwks',
                'response_types_supported' => ['code'],
                'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            ], $discovery)),
            self::ISSUER.'/jwks' => function () {
                $this->jwksFetches++;

                return Http::response(['keys' => $this->jwks]);
            },
            self::ISSUER.'/token' => function (Request $request) {
                $this->tokenRequests[] = $request;

                return Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->tokenOverride ?? $this->idToken()]);
            },
        ]);
    }

    protected function provider(array $attributes = []): SsoProvider
    {
        $provider = new SsoProvider(array_merge([
            'name' => 'Example ID',
            'preset' => 'oidc',
            'issuer' => self::ISSUER,
            'client_id' => 'labeldesk-client',
            'scopes' => 'openid email profile',
            'allowed_domains' => ['label.test'],
            'enabled' => true,
        ], $attributes));
        $provider->client_secret = 'top-secret-value';
        $provider->slug = $attributes['slug'] ?? SsoProvider::uniqueSlug($provider->name);
        $provider->save();

        return $provider;
    }

    // ------------------------------------------------------------ flow helpers

    /** @return array<string, string> query of the authorization redirect */
    protected function startSignIn(SsoProvider $provider): array
    {
        $location = $this->get(route('sso.redirect', $provider))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /** Run the whole flow: start, then come back from the provider with a code. */
    protected function signIn(SsoProvider $provider, array $claims = []): TestResponse
    {
        $query = $this->startSignIn($provider);
        $this->tokenClaims = array_merge(['nonce' => $query['nonce']], $claims);

        return $this->get(route('sso.callback', $provider).'?'.http_build_query(['code' => 'auth-code-123', 'state' => $query['state']]));
    }

    // ------------------------------------------------------------ tokens

    protected function idToken(?array $claims = null, ?string $alg = null, ?string $kid = null, ?string $keyFile = null): string
    {
        $alg ??= $this->tokenAlg;
        $kid ??= $this->tokenKid;
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => 'labeldesk-client',
            'sub' => 'subject-1',
            'email' => 'staff@label.test',
            'email_verified' => true,
            'name' => 'Sam Staff',
            'iat' => time(),
            'exp' => time() + 300,
        ], $claims ?? $this->tokenClaims);

        return static::jwt(['alg' => $alg, 'kid' => $kid, 'typ' => 'JWT'], $claims, $keyFile ?? (str_starts_with($alg, 'ES') ? 'ec.pem' : 'rsa.pem'));
    }

    public static function jwt(array $header, array $claims, string $keyFile): string
    {
        $input = static::b64(json_encode($header)).'.'.static::b64(json_encode($claims));
        $alg = $header['alg'] ?? 'RS256';
        if (! in_array($alg, ['RS256', 'ES256'], true)) {
            return $input.'.'.static::b64('not-a-signature');
        }
        $key = openssl_pkey_get_private(file_get_contents(__DIR__.'/keys/'.$keyFile));
        openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
        $signature = $alg === 'ES256' ? static::derToRaw($der, 32) : $der;

        return $input.'.'.static::b64($signature);
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** DER ECDSA signature → JOSE r || s. */
    protected static function derToRaw(string $der, int $size): string
    {
        $offset = 2;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $parts[] = str_pad(ltrim($int, "\x00"), $size, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return implode('', $parts);
    }

    public static function rsaJwk(string $file, string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents(__DIR__.'/keys/'.$file)));

        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => static::b64($details['rsa']['n']), 'e' => static::b64($details['rsa']['e'])];
    }

    public static function ecJwk(string $file, string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents(__DIR__.'/keys/'.$file)));

        return ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'crv' => 'P-256',
            'x' => static::b64(str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)),
            'y' => static::b64(str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT))];
    }
}

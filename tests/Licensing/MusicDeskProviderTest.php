<?php

namespace Tests\Licensing;

use App\Exceptions\LicenseException;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseSignatureVerifier;
use App\Services\License\Providers\MusicDeskLicenseProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The production provider against a faked MusicDesk license server
 * (api/license/v1), with tokens signed by a throw-away Ed25519 key pair
 * standing in for MusicDesk's private key — exactly as the server signs them.
 */
class MusicDeskProviderTest extends TestCase
{
    protected string $keypair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keypair = sodium_crypto_sign_keypair();
        config([
            'licensing.driver' => 'musicdesk',
            'licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($this->keypair)),
            'licensing.api_base_url' => 'https://musicdesk.tech/api/license/v1',
            'licensing.issuer' => 'https://musicdesk.tech',
            'licensing.retry_delay_ms' => 1,
        ]);
    }

    protected function context(): ActivationContext
    {
        return ActivationContext::for('https://label.example.com', self::INSTALLATION_ID);
    }

    /**
     * A JWT built the way MusicDesk's LicenseToken::issue() builds it.
     */
    protected function jwt(array $overrides = [], ?string $secretKey = null): string
    {
        $now = time();
        $claims = array_merge([
            'lic' => 'lic-uuid', 'act' => 'act-uuid', 'dom' => 'label.example.com', 'env' => 'production',
            'plan' => 'business', 'status' => 'active', 'support_until' => $now + 86400 * 365, 'max_version' => null,
            'iss' => 'https://musicdesk.tech', 'iat' => $now, 'exp' => $now + 86400 * 30,
        ], $overrides);
        $input = LicenseSignatureVerifier::b64(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'test']))
            .'.'.LicenseSignatureVerifier::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $input.'.'.LicenseSignatureVerifier::b64(sodium_crypto_sign_detached($input, $secretKey ?? sodium_crypto_sign_secretkey($this->keypair)));
    }

    protected function ok(array $tokenOverrides = [], array $extra = []): array
    {
        return array_merge([
            'token' => $this->jwt($tokenOverrides),
            'status' => $tokenOverrides['status'] ?? 'active',
            'support_until' => '2027-03-14T10:00:00Z',
            'max_version' => null,
            'check_in_requested' => false,
            'messages' => [],
        ], $extra);
    }

    public function test_activation_sends_the_documented_request_and_verifies_the_token(): void
    {
        Http::fake(['musicdesk.tech/*' => Http::response($this->ok())]);

        $result = app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');

        $this->assertSame('business', $result->plan);
        $this->assertSame('act-uuid', $result->activationId);
        $this->assertSame('production', $result->environment);
        // business = every feature (config/licensing.php plans map); no feature list in the token.
        $this->assertContains('custom_roles', $result->features);
        $this->assertNull($result->userLimit);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://musicdesk.tech/api/license/v1/activate'
            && $r['key'] === 'LDSK-AAAA-BBBB-CCCC-DDDD'
            && $r['domain'] === 'label.example.com'
            && $r['instance_id'] === self::INSTALLATION_ID
            && $r['php_version'] === PHP_VERSION
            && $r->hasHeader('Accept', 'application/json'));
    }

    public function test_starter_plan_features_come_from_the_plan_map(): void
    {
        Http::fake(['musicdesk.tech/*' => Http::response($this->ok(['plan' => 'starter']))]);

        $result = app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');

        $this->assertContains('royalties', $result->features);
        $this->assertNotContains('custom_roles', $result->features);
    }

    public function test_check_in_posts_the_token_and_returns_a_fresh_one(): void
    {
        $old = $this->jwt(['exp' => time() - 10, 'iat' => time() - 86400 * 31]);
        Http::fake(['musicdesk.tech/*' => Http::response($this->ok([], ['check_in_requested' => true, 'messages' => ['Your annual license has expired.']]))]);

        $result = app(MusicDeskLicenseProvider::class)->checkIn($this->context(), $old);

        $this->assertTrue($result->validUntil->isFuture());
        $this->assertTrue($result->checkInRequested);
        $this->assertSame(['Your annual license has expired.'], $result->messages);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/check-in') && $r['token'] === $old);
    }

    public function test_usable_statuses_follow_musicdesk_rules(): void
    {
        $statuses = ['active', 'expiring', 'expired', 'pending'];
        $sequence = Http::sequence();
        foreach ($statuses as $status) {
            $sequence->push($this->ok(['status' => $status]));
        }
        Http::fake(['musicdesk.tech/*' => $sequence]);

        foreach ($statuses as $status) {
            $result = app(LicenseActivationService::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD', 'musicdesk');
            $this->assertSame($status, $result->status);
        }
    }

    public function test_deactivation_accepts_204(): void
    {
        Http::fake(['musicdesk.tech/*' => Http::response(null, 204)]);

        app(MusicDeskLicenseProvider::class)->deactivate($this->context(), $this->jwt());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deactivate') && isset($r['token']));
    }

    public function test_forged_signature_is_rejected(): void
    {
        $attacker = sodium_crypto_sign_keypair();
        Http::fake(['musicdesk.tech/*' => Http::response(['token' => $this->jwt([], sodium_crypto_sign_secretkey($attacker)), 'status' => 'active'])]);

        $this->expectException(LicenseException::class);
        $this->expectExceptionMessage('could not be verified');

        app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
    }

    public function test_token_for_another_domain_or_issuer_is_rejected(): void
    {
        Http::fake(['musicdesk.tech/*' => Http::sequence()
            ->push($this->ok(['dom' => 'other.example.org']))
            ->push($this->ok(['iss' => 'https://evil.example']))]);

        foreach (['domain_mismatch', 'wrong_issuer'] as $code) {
            try {
                app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
                $this->fail("Expected {$code}");
            } catch (LicenseException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function test_www_and_port_are_normalised_like_the_server(): void
    {
        $context = ActivationContext::for('https://WWW.Label.Example.com:8443/app', self::INSTALLATION_ID);
        $this->assertSame('label.example.com', $context->domain);

        Http::fake(['musicdesk.tech/*' => Http::response($this->ok())]);
        $this->assertSame('business', app(MusicDeskLicenseProvider::class)->activate($context, 'LDSK-AAAA-BBBB-CCCC-DDDD')->plan);
    }

    public function test_api_errors_are_mapped(): void
    {
        $cases = [
            ['license_suspended', 403], ['license_revoked', 403], ['domain_limit_reached', 409],
            ['domain_in_use', 409], ['invalid_key', 404], ['invalid_domain', 422],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$code, $status]) {
            $sequence->push(['error' => ['code' => $code, 'message' => '<b>x</b>', 'field' => null]], $status);
        }
        Http::fake(['musicdesk.tech/*' => $sequence]);

        foreach ($cases as [$code]) {
            try {
                app(LicenseActivationService::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD', 'musicdesk');
                $this->fail("Expected {$code}");
            } catch (LicenseException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertStringNotContainsString('<b>', $e->getMessage());
            }
        }
    }

    public function test_rate_limit_is_reported(): void
    {
        Http::fake(['musicdesk.tech/*' => Http::response('Too Many Requests', 429)]);

        try {
            app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
            $this->fail('Expected rate limit');
        } catch (LicenseException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
        }
    }

    public function test_response_without_token_fails_closed(): void
    {
        Http::fake(['musicdesk.tech/*' => Http::response(['status' => 'active'])]);

        $this->expectException(LicenseException::class);

        app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
    }

    public function test_fails_closed_without_a_valid_public_key(): void
    {
        config(['licensing.public_key' => 'not-a-key']);
        Http::fake();

        try {
            app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
            $this->fail('Expected not_configured');
        } catch (LicenseException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_shipped_public_key_matches_the_musicdesk_key_id(): void
    {
        $key = base64_decode((string) config('licensing.public_key', ''), true);
        config(['licensing.public_key' => require base_path('config/licensing.php')]);

        $shipped = (require base_path('config/licensing.php'))['public_key'];
        $raw = base64_decode($shipped, true);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) $raw));
        $this->assertSame('75338fabc5122988', substr(hash('sha256', (string) $raw), 0, 16));
        $this->assertNotFalse($key);
    }

    public function test_plain_http_endpoints_are_refused(): void
    {
        config(['licensing.api_base_url' => 'http://musicdesk.tech/api/license/v1']);
        Http::fake();

        try {
            app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
            $this->fail('Expected insecure endpoint error');
        } catch (LicenseException $e) {
            $this->assertSame('insecure_endpoint', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_unreachable_service_is_retried_then_reported(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('timeout');
        });

        try {
            app(MusicDeskLicenseProvider::class)->activate($this->context(), 'LDSK-AAAA-BBBB-CCCC-DDDD');
            $this->fail('Expected unreachable');
        } catch (LicenseException $e) {
            $this->assertSame('unreachable', $e->errorCode);
            $this->assertTrue($e->retryable);
        }
        $this->assertSame(3, $calls);
    }
}

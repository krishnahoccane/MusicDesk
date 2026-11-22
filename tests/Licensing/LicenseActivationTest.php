<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Exceptions\LicenseException;
use App\Models\LicenseEvent;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function context(): ActivationContext
    {
        return ActivationContext::for('https://label.example.com', self::INSTALLATION_ID);
    }

    protected function expectLicenseError(string $key, string $code): void
    {
        try {
            app(LicenseActivationService::class)->activate($this->context(), $key, 'mock');
            $this->fail("Expected license error [{$code}].");
        } catch (LicenseException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_valid_license_activates_and_is_persisted_encrypted(): void
    {
        $activation = $this->activateLicense('ACTV');

        $this->assertSame('active', $activation->status);
        $this->assertSame('business', $activation->plan);
        $this->assertSame(hash('sha256', 'MD-LD-TEST-ACTV-AAAA-BBBB'), $activation->license_key_hash);
        $this->assertStringNotContainsString('ACTV', (string) $activation->getRawOriginal('license_key'));
        $this->assertSame('MD-LD-••••-••••-••••-BBBB', $activation->maskedKey());
        $this->assertSame(1, LicenseEvent::query()->where('event', 'activated')->count());

        $this->assertSame(LicenseState::Active, app(LicenseValidationService::class)->snapshot()->state);
    }

    public function test_invalid_license_is_rejected(): void
    {
        $this->expectLicenseError('MD-LD-TEST-NOPE-AAAA-BBBB', 'invalid_key');
        $this->expectLicenseError('not a key', 'invalid_key');
    }

    public function test_expired_license_is_rejected(): void
    {
        $this->expectLicenseError('MD-LD-TEST-EXPD-AAAA-BBBB', 'license_expired');
    }

    public function test_suspended_license_is_rejected(): void
    {
        $this->expectLicenseError('MD-LD-TEST-SUSP-AAAA-BBBB', 'license_suspended');
    }

    public function test_activation_limit_is_enforced_by_the_authority(): void
    {
        $this->expectLicenseError('MD-LD-TEST-LIMT-AAAA-BBBB', 'domain_limit_reached');
    }

    public function test_format_alone_never_activates_a_license(): void
    {
        // Perfectly formatted, but unknown to the authority.
        $this->expectLicenseError('MD-LD-7K2P-9QWE-ZX4C-M1N8', 'invalid_key');
    }

    public function test_unlicensed_installation_is_reported(): void
    {
        $this->assertSame(LicenseState::Unlicensed, app(LicenseValidationService::class)->snapshot()->state);
    }

    public function test_mock_provider_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectLicenseError('MD-LD-TEST-ACTV-AAAA-BBBB', 'mock_forbidden');
    }

    public function test_reactivation_retires_previous_activation(): void
    {
        $first = $this->activateLicense('ACTV');
        $second = $this->activateLicense('STRT');

        $this->assertSame('deactivated', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame('starter', app(LicenseValidationService::class)->snapshot()->result->plan);
    }
}

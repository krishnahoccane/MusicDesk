<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Enums\SystemRole;
use App\Exceptions\LicenseException;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseManager;
use App\Services\License\LicenseValidationService;
use App\Services\License\Providers\MockLicenseProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Check-in, offline files, re-activation, deactivation and transfer against
 * the development mock, which issues tokens in the MusicDesk server format.
 */
class LicenseServerFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
    }

    protected function context(): ActivationContext
    {
        return ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
    }

    protected function mockAuthority(): MockLicenseProvider
    {
        return app(LicenseManager::class)->provider('mock');
    }

    public function test_check_in_replaces_the_token(): void
    {
        $activation = $this->activateLicense();
        $first = app(LicenseActivationService::class)->currentToken($activation);

        $this->travel(2)->days();
        app(LicenseValidationService::class)->refresh($this->admin);

        $this->assertNotSame($first, app(LicenseActivationService::class)->currentToken($activation->fresh()));
        $this->assertDatabaseHas('license_events', ['event' => 'verified', 'result' => 'success']);
    }

    public function test_pending_license_works_and_shows_the_server_message(): void
    {
        $activation = $this->activateLicense('PEND');

        $this->assertSame(LicenseState::Active, app(LicenseValidationService::class)->snapshot(fresh: true)->state);
        $this->assertSame(['Awaiting payment (development mock).'], $activation->fresh()->messages);
        $this->actingAs($this->admin)->get('/settings/license')->assertSee('Awaiting payment');
    }

    public function test_domain_deactivated_in_the_portal_makes_the_install_read_only(): void
    {
        $activation = $this->activateLicense();
        $this->mockAuthority()->deactivate($this->context(), (string) app(LicenseActivationService::class)->currentToken($activation), reason: 'portal');

        try {
            app(LicenseValidationService::class)->refresh();
            $this->fail('Expected activation_deactivated');
        } catch (LicenseException $e) {
            $this->assertSame('activation_deactivated', $e->errorCode);
        }

        $this->assertSame('deactivated', $activation->fresh()->license_status);
        $this->assertSame(LicenseState::Restricted, app(LicenseValidationService::class)->snapshot(fresh: true)->state);
    }

    public function test_missing_token_is_recovered_with_the_stored_key(): void
    {
        $activation = $this->activateLicense();
        $activation->caches()->delete();

        app(LicenseValidationService::class)->refresh();

        $this->assertNotNull(app(LicenseActivationService::class)->currentToken($activation->fresh()));
        $this->assertSame(LicenseState::Active, app(LicenseValidationService::class)->snapshot(fresh: true)->state);
    }

    public function test_offline_activation_file(): void
    {
        $token = $this->mockAuthority()->issue($this->context(), 'act-offline', MockLicenseProvider::PLANS['ACTV'], 365)->token();
        $file = UploadedFile::fake()->createWithContent('activation.ldlic', json_encode(['format' => 'ldlic-1', 'license' => 'LIC-ABC123', 'domain' => $this->context()->domain, 'token' => $token]));

        $this->actingAs($this->admin)->post('/settings/license/offline', ['license_file' => $file])->assertSessionHasNoErrors()->assertSessionHas('success');

        $activation = LicenseActivation::query()->where('status', 'active')->sole();
        $this->assertTrue($activation->offline);
        $this->assertSame('Offline activation file', $activation->maskedKey());
        $snapshot = app(LicenseValidationService::class)->snapshot(fresh: true);
        $this->assertSame(LicenseState::Active, $snapshot->state);
        $this->assertTrue($snapshot->result->validUntil->greaterThan(now()->addDays(360)));
    }

    public function test_offline_file_for_another_domain_or_tampered_is_refused(): void
    {
        $other = ActivationContext::for('https://other.example.org', self::INSTALLATION_ID);
        $foreign = $this->mockAuthority()->issue($other, 'act-x', MockLicenseProvider::PLANS['ACTV'], 365)->token();
        $good = $this->mockAuthority()->issue($this->context(), 'act-y', MockLicenseProvider::PLANS['ACTV'], 365)->token();
        $tampered = substr($good, 0, -4).'AAAA';

        foreach (['{"format":"nope"}', json_encode(['format' => 'ldlic-1', 'token' => $foreign]), json_encode(['format' => 'ldlic-1', 'token' => $tampered])] as $contents) {
            $file = UploadedFile::fake()->createWithContent('a.ldlic', $contents);
            $this->actingAs($this->admin)->post('/settings/license/offline', ['license_file' => $file])->assertSessionHasErrors('license_file');
        }

        $this->assertSame(0, LicenseActivation::query()->count());
    }

    public function test_offline_request_file_contains_only_installation_details(): void
    {
        $response = $this->actingAs($this->admin)->get('/settings/license/request-file')->assertOk();
        $data = json_decode($response->getContent(), true);

        $this->assertSame('ldreq-1', $data['format']);
        $this->assertSame($this->context()->domain, $data['domain']);
        $this->assertSame(self::INSTALLATION_ID, $data['instance_id']);
        $this->assertSame(['domain', 'format', 'instance_id', 'php_version', 'product', 'version'], collect($data)->keys()->sort()->values()->all());
    }

    public function test_transfer_moves_the_activation_to_the_new_domain(): void
    {
        $activation = $this->activateLicense();
        config(['app.url' => 'https://new-label.example.com']);

        $this->actingAs($this->admin)->post('/settings/license/transfer', ['password' => 'password'])->assertSessionHas('success');

        $activation->refresh();
        $this->assertSame('new-label.example.com', $activation->domain);
        app(LicenseValidationService::class)->forget();
        $this->assertSame(LicenseState::Active, app(LicenseValidationService::class)->snapshot(fresh: true)->state);
    }

    public function test_deactivation_frees_the_domain_and_goes_read_only(): void
    {
        $activation = $this->activateLicense();

        $this->actingAs($this->admin)->post('/settings/license/deactivate/request', ['password' => 'password'])->assertSessionHas('deactivate_code_sent');
        $this->actingAs($this->admin)->post('/settings/license/deactivate', ['code' => MockLicenseProvider::DEACTIVATION_CODE])->assertSessionHas('success');

        $this->assertSame('deactivated', $activation->fresh()->status);
        $this->assertSame(LicenseState::Unlicensed, app(LicenseValidationService::class)->snapshot(fresh: true)->state);
    }
}

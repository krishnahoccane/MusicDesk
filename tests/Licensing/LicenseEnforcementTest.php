<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Enums\SystemRole;
use App\Models\User;
use App\Services\License\LicenseFeatureService;
use App\Services\License\LicenseManager;
use App\Services\License\LicenseValidationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Licensing\Support\OfflineMockLicenseProvider;
use Tests\TestCase;

class LicenseEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->fresh();
        $this->admin->assignRole(SystemRole::SuperAdmin->value);

        // A plan-gated test route behind the same middleware stack.
        Route::middleware(['web', 'auth', 'license', 'feature:royalties'])
            ->get('/_test/royalties', fn () => 'royalties ok');
        Route::middleware(['web', 'auth', 'license'])
            ->post('/_test/write', fn () => 'written');
    }

    protected function goOffline(): void
    {
        app(LicenseManager::class)->extend('mock', OfflineMockLicenseProvider::class);
    }

    protected function snapshotState(): LicenseState
    {
        return app(LicenseValidationService::class)->snapshot(fresh: true)->state;
    }

    public function test_offline_grace_period_then_restricted_mode(): void
    {
        $this->activateLicense('ACTV'); // tokens are valid for 30 days, like MusicDesk's
        config(['licensing.offline_grace_period' => 14]);
        $this->goOffline();

        $this->travel(20)->days();
        $this->assertSame(LicenseState::Active, $this->snapshotState());

        $this->travel(15)->days();   // day 35: token expired, within grace
        $this->assertSame(LicenseState::GracePeriod, $this->snapshotState());
        $this->actingAs($this->admin)->post('/_test/write')->assertOk();

        $this->travel(15)->days();   // day 50: grace over
        $this->assertSame(LicenseState::Restricted, $this->snapshotState());
    }

    public function test_restricted_mode_is_read_only_but_never_blocks_license_pages(): void
    {
        $this->activateLicense('ACTV');
        $this->goOffline();
        $this->travel(60)->days();
        app(LicenseValidationService::class)->forget();

        $this->actingAs($this->admin);
        $this->get('/dashboard')->assertOk()->assertSee('read-only');
        $this->post('/_test/write')->assertRedirect(route('license.show'));
        $this->get('/settings/license')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_tampered_cache_is_not_trusted(): void
    {
        $activation = $this->activateLicense('ACTV');
        $cache = $activation->caches()->first();

        [$header, $claims] = explode('.', $cache->payload);
        $data = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $data['plan'] = 'enterprise';
        $data['exp'] = now()->addYears(10)->timestamp;
        $cache->update(['payload' => $header.'.'.rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=')]);

        $this->assertSame(LicenseState::Restricted, $this->snapshotState());
    }

    public function test_feature_entitlements_follow_the_plan(): void
    {
        $this->activateLicense('STRT');
        $features = app(LicenseFeatureService::class);

        $this->assertTrue($features->canUse('artists'));
        $this->assertFalse($features->canUse('royalties'));
        $this->assertFalse($features->canUse('custom_roles'));

        $this->actingAs($this->admin)->get('/_test/royalties')->assertForbidden()->assertSee('not included in your plan');
    }

    public function test_feature_is_available_on_higher_plan(): void
    {
        $this->activateLicense('ACTV');

        $this->actingAs($this->admin)->get('/_test/royalties')->assertOk()->assertSee('royalties ok');
    }

    public function test_unlicensed_installation_cannot_use_licensed_features(): void
    {
        $this->assertFalse(app(LicenseFeatureService::class)->canUse('artists'));
        $this->actingAs($this->admin)->post('/_test/write')->assertRedirect(route('license.show'));
    }
}

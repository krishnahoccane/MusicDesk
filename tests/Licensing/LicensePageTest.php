<?php

namespace Tests\Licensing;

use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\License\Providers\MockLicenseProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicensePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function userWithRole(SystemRole $role): User
    {
        $user = User::factory()->create()->fresh();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_license_page_shows_masked_details(): void
    {
        $this->activateLicense('ACTV');

        $this->actingAs($this->userWithRole(SystemRole::SuperAdmin))
            ->get('/settings/license')
            ->assertOk()
            ->assertSee('MD-LD-••••-••••-••••-BBBB')
            ->assertDontSee('MD-LD-TEST-ACTV-AAAA-BBBB')
            ->assertSee('business')
            ->assertSee('Verify now');
    }

    public function test_verify_now_refreshes_the_license(): void
    {
        $this->activateLicense('ACTV');

        $this->actingAs($this->userWithRole(SystemRole::SuperAdmin))
            ->post('/settings/license/verify')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('license_events', ['event' => 'verified', 'result' => 'success']);
    }

    public function test_deactivation_needs_the_password_and_the_emailed_code(): void
    {
        $activation = $this->activateLicense('ACTV');
        $admin = $this->userWithRole(SystemRole::SuperAdmin);

        // The dialog sits outside the page content (so the backdrop cannot cover it).
        $page = $this->actingAs($admin)->get('/settings/license')->assertOk()->getContent();
        $this->assertGreaterThan(strpos($page, '</main>'), strpos($page, 'id="deactivate-modal"'));

        // No code request yet: deactivating straight away is refused.
        $this->actingAs($admin)->post('/settings/license/deactivate', ['code' => MockLicenseProvider::DEACTIVATION_CODE])->assertSessionHas('error');

        $this->actingAs($admin)->post('/settings/license/deactivate/request', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->post('/settings/license/deactivate/request', ['password' => 'password'])
            ->assertSessionHas('deactivate_code_sent')->assertSessionHas('license_deactivate');
        $this->assertDatabaseHas('license_events', ['event' => 'deactivation_code']);
        $this->actingAs($admin)->get('/settings/license')->assertSee('Code from the email');

        $this->actingAs($admin)->post('/settings/license/deactivate', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame('active', $activation->fresh()->status);

        $this->actingAs($admin)->post('/settings/license/deactivate', ['code' => MockLicenseProvider::DEACTIVATION_CODE])->assertSessionHas('success');
        $this->assertSame('deactivated', $activation->fresh()->status);
        $this->assertSame(0, $activation->caches()->count());
    }

    public function test_an_expired_code_request_must_be_started_again(): void
    {
        $activation = $this->activateLicense('ACTV');
        $admin = $this->userWithRole(SystemRole::SuperAdmin);

        $this->actingAs($admin)->post('/settings/license/deactivate/request', ['password' => 'password']);
        $this->travel(11)->minutes();
        $this->actingAs($admin)->post('/settings/license/deactivate', ['code' => MockLicenseProvider::DEACTIVATION_CODE])->assertSessionHas('error');
        $this->assertSame('active', $activation->fresh()->status);
    }

    public function test_viewer_cannot_see_or_manage_the_license(): void
    {
        $this->activateLicense('ACTV');
        $viewer = $this->userWithRole(SystemRole::Viewer);

        $this->actingAs($viewer)->get('/settings/license')->assertForbidden();
        $this->actingAs($viewer)->post('/settings/license/verify')->assertForbidden();
        $this->actingAs($viewer)->post('/settings/license/deactivate/request', ['password' => 'password'])->assertForbidden();
    }

    public function test_label_manager_can_view_but_not_manage(): void
    {
        $this->activateLicense('ACTV');
        $manager = $this->userWithRole(SystemRole::LabelManager);

        $this->actingAs($manager)->get('/settings/license')->assertOk()->assertDontSee('Deactivate…');
        $this->actingAs($manager)->post('/settings/license/deactivate/request', ['password' => 'password'])->assertForbidden();
    }

    public function test_reactivation_after_deactivation(): void
    {
        $this->activateLicense('ACTV')->update(['status' => 'deactivated']);

        $this->actingAs($this->userWithRole(SystemRole::SuperAdmin))
            ->post('/settings/license/activate', ['license_key' => 'MD-LD-TEST-STRT-AAAA-BBBB'])
            ->assertSessionHas('success');

        $this->assertSame('starter', LicenseActivation::query()->where('status', 'active')->value('plan'));
    }
}

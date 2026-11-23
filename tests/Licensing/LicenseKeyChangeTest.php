<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseCacheService;
use App\Services\License\LicenseValidationService;
use App\Services\License\Providers\MockLicenseProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing the license key on the License page (signed in, password confirmed)
 * and on the suspension page (administrator email + password, nobody signed in).
 */
class LicenseKeyChangeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected LicenseActivation $activation;

    protected const NEW_KEY = 'MD-LD-TEST-STRT-CCCC-DDDD';

    protected function setUp(): void
    {
        parent::setUp();
        config(['labeldesk.display_timezone' => 'UTC']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activation = $this->activateLicense();
    }

    protected function lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-20'));
        $context = ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
        $result = app(MockLicenseProvider::class)->issue($context, (string) $this->activation->activation_id, [
            'plan' => 'business', 'status' => 'active', 'user_limit' => 25, 'features' => ['artists', 'releases', 'tracks'],
            'expires' => CarbonImmutable::parse('2026-12-31 12:00')->timestamp,
        ], 30);
        app(LicenseActivationService::class)->applyResult($this->activation, $result);
        app(LicenseCacheService::class)->store($this->activation, $result);
        $this->travelTo(CarbonImmutable::parse('2027-01-15 09:00'));
        $this->assertSame(LicenseState::Locked, $this->state());
    }

    protected function state(): LicenseState
    {
        app(LicenseValidationService::class)->forget();

        return app(LicenseValidationService::class)->snapshot()->state;
    }

    protected function currentKey(): string
    {
        return (string) LicenseActivation::query()->where('status', 'active')->latest('id')->value('license_key');
    }

    public function test_license_page_changes_the_key_after_password_confirmation(): void
    {
        $this->actingAs($this->admin)->get(route('license.show'))->assertOk()->assertSee('Change license key');

        $this->actingAs($this->admin)->post(route('license.change-key'), ['new_license_key' => self::NEW_KEY, 'change_password' => 'wrong'])
            ->assertSessionHasErrors('change_password');
        $this->actingAs($this->admin)->post(route('license.change-key'), ['new_license_key' => 'MD-LD-TEST-REVK-AAAA-BBBB', 'change_password' => 'password'])
            ->assertSessionHasErrors('new_license_key');
        $this->actingAs($this->admin)->post(route('license.change-key'), ['new_license_key' => 'md-ld-test-actv-aaaa-bbbb', 'change_password' => 'password'])
            ->assertSessionHasErrors('new_license_key'); // same key
        $this->assertSame('MD-LD-TEST-ACTV-AAAA-BBBB', $this->currentKey(), 'refused keys change nothing');

        $this->actingAs($this->admin)->post(route('license.change-key'), ['new_license_key' => strtolower(self::NEW_KEY), 'change_password' => 'password'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(self::NEW_KEY, $this->currentKey());
        $this->assertSame('deactivated', $this->activation->fresh()->status);
        $this->assertSame('starter', app(LicenseValidationService::class)->snapshot(fresh: true)->result?->plan);
        $this->assertDatabaseHas('license_events', ['event' => 'key_changed']);
    }

    public function test_only_license_managers_can_change_the_key(): void
    {
        $manager = $this->makeUser(SystemRole::LabelManager);
        $this->actingAs($manager)->post(route('license.change-key'), ['new_license_key' => self::NEW_KEY, 'change_password' => 'password'])->assertForbidden();
        $this->assertSame('MD-LD-TEST-ACTV-AAAA-BBBB', $this->currentKey());
    }

    public function test_suspended_page_lets_an_administrator_change_the_key(): void
    {
        $this->lock();

        $this->get('/suspended')->assertStatus(403)->assertSee('Administrator: use a different license key');

        // Wrong password, inactive admin and a non-admin are all refused the same way.
        $manager = $this->makeUser(SystemRole::LabelManager);
        $inactive = $this->makeUser(SystemRole::SuperAdmin);
        $inactive->forceFill(['is_active' => false])->save();
        foreach ([[$this->admin->email, 'wrong'], [$inactive->email, 'password'], [$manager->email, 'password'], ['nobody@example.com', 'password']] as [$email, $password]) {
            $this->from('/suspended')->post(route('license.locked.change-key'), ['email' => $email, 'password' => $password, 'license_key' => self::NEW_KEY])
                ->assertRedirect('/suspended')->assertSessionHasErrorsIn('changeKey', 'email');
        }
        $this->assertSame(LicenseState::Locked, $this->state());

        $this->from('/suspended')->post(route('license.locked.change-key'), ['email' => $this->admin->email, 'password' => 'password', 'license_key' => 'MD-LD-TEST-EXPD-AAAA-BBBB'])
            ->assertSessionHasErrorsIn('changeKey', 'license_key');
        $this->assertSame(LicenseState::Locked, $this->state());
    }

    public function test_a_valid_new_key_on_the_suspended_page_restores_access(): void
    {
        $this->lock();

        $this->from('/suspended')->post(route('license.locked.change-key'), ['email' => $this->admin->email, 'password' => 'password', 'license_key' => self::NEW_KEY])
            ->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertGuest(); // nobody is signed in by the key change
        $this->assertSame(LicenseState::Active, $this->state());
        $this->assertSame(self::NEW_KEY, $this->currentKey());
        $this->get('/suspended')->assertRedirect('/');
    }

    public function test_the_suspended_form_is_rate_limited(): void
    {
        $this->lock();
        foreach (range(1, 5) as $i) {
            $this->post(route('license.locked.change-key'), ['email' => $this->admin->email, 'password' => 'wrong', 'license_key' => self::NEW_KEY]);
        }
        $this->post(route('license.locked.change-key'), ['email' => $this->admin->email, 'password' => 'password', 'license_key' => self::NEW_KEY])->assertStatus(429);
    }
}

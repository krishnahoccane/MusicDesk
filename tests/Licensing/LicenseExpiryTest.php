<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Enums\SystemRole;
use App\Livewire\Settings\Catalog;
use App\Models\Artist;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseCacheService;
use App\Services\License\LicenseValidationService;
use App\Services\License\Providers\MockLicenseProvider;
use App\Services\System\AutomaticTasks;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Expired licenses: read-only from the expiry date, sign-in until expiry + 10
 * days, then the whole application is suspended until renewal.
 */
class LicenseExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected LicenseActivation $activation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['labeldesk.display_timezone' => 'UTC']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activation = $this->activateLicense();
    }

    /** Store a freshly signed token whose license expires at $expires (null = lifetime). */
    protected function licenseExpires(?CarbonImmutable $expires, string $status = 'active'): void
    {
        $context = ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
        $result = app(MockLicenseProvider::class)->issue($context, (string) $this->activation->activation_id, [
            'plan' => 'business', 'status' => $status, 'user_limit' => 25,
            'features' => ['artists', 'releases', 'tracks', 'distribution', 'royalties', 'documents', 'reports', 'advanced_reports', 'financial_reports', 'custom_roles', 'backups'],
            'expires' => $expires?->timestamp,
        ], 30);

        app(LicenseActivationService::class)->applyResult($this->activation, $result);
        app(LicenseCacheService::class)->store($this->activation, $result);
        app(LicenseValidationService::class)->forget();
    }

    protected function state(): LicenseState
    {
        app(LicenseValidationService::class)->forget();

        return app(LicenseValidationService::class)->snapshot()->state;
    }

    public function test_read_only_through_expiry_plus_ten_days_then_locked(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
        $this->licenseExpires(CarbonImmutable::parse('2026-12-31 18:00:00'));
        $this->assertSame(LicenseState::Active, $this->state());

        $this->travelTo(CarbonImmutable::parse('2026-12-31 18:00:01'));
        $this->assertSame(LicenseState::Expired, $this->state());
        $snapshot = app(LicenseValidationService::class)->snapshot();
        $this->assertSame('2027-01-11 00:00:00', $snapshot->lockAt->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-10', $snapshot->lastAccessDay()->format('Y-m-d'));
        $this->assertFalse($snapshot->allowsWrites());

        $this->travelTo(CarbonImmutable::parse('2027-01-10 23:59:59'));
        $this->assertSame(LicenseState::Expired, $this->state(), 'sign-in still allowed on 10 Jan');

        $this->travelTo(CarbonImmutable::parse('2027-01-11 00:00:00'));
        $this->assertSame(LicenseState::Locked, $this->state(), 'locked from 11 Jan');
    }

    public function test_expired_site_is_readable_but_read_only_with_ribbons(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-20'));
        $this->licenseExpires(CarbonImmutable::parse('2026-12-31 12:00'));
        $this->travelTo(CarbonImmutable::parse('2027-01-03 09:00'));

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSee('License expired')
            ->assertSee('This application will be locked on Jan 11, 2027');
        $this->actingAs($this->admin)->get('/artists')->assertOk()->assertSee('Read-only mode. This site will be locked on Jan 11, 2027');

        // Writes are refused.
        $this->actingAs($this->admin)->post('/settings/license/verify')->assertRedirect();
        Livewire::actingAs($this->admin)->test(Catalog::class)
            ->set('newGenre', 'Should not be saved')->call('addGenre')->assertForbidden();
        $this->assertDatabaseMissing('genres', ['name' => 'Should not be saved']);

        // The sign-in page shows the ribbon too, and sign-in still works.
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('License expired');
    }

    public function test_locked_site_signs_everyone_out_and_blocks_sign_in(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-20'));
        $this->licenseExpires(CarbonImmutable::parse('2026-12-31 12:00'));
        $this->travelTo(CarbonImmutable::parse('2027-01-12 09:00'));

        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect(route('license.locked'));
        $this->assertGuest();

        $this->get('/login')->assertRedirect(route('license.locked'));
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect(route('license.locked'));
        $this->assertGuest();

        $this->get('/suspended')->assertStatus(403)
            ->assertSee('This application is suspended')
            ->assertSee('Dec 31, 2026')
            ->assertSee('Jan 11, 2027');

        // "Check again" while still expired keeps it locked (the mock re-issues the same expiry).
        $this->post('/suspended/check')->assertRedirect(route('license.locked'));
        $this->assertSame(LicenseState::Locked, $this->state());
    }

    public function test_renewal_restores_access(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-20'));
        $this->licenseExpires(CarbonImmutable::parse('2026-12-31 12:00'));
        $this->travelTo(CarbonImmutable::parse('2027-01-15'));
        $this->assertSame(LicenseState::Locked, $this->state());

        $this->licenseExpires(CarbonImmutable::parse('2027-12-31 12:00'));
        $this->assertSame(LicenseState::Active, $this->state());
        $this->get('/suspended')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertDontSee('License expired');
        $this->assertNull($this->activation->fresh()->expired_detected_at);
    }

    public function test_expiry_known_only_from_the_server_status_uses_the_day_it_was_first_seen(): void
    {
        $this->activation->forceFill(['license_status' => 'expired', 'expired_detected_at' => now()->subDays(4)])->save();
        $this->activation->caches()->delete();
        $this->assertSame(LicenseState::Expired, $this->state());

        $this->activation->forceFill(['expired_detected_at' => now()->subDays(12)])->save();
        $this->assertSame(LicenseState::Locked, $this->state());
    }

    public function test_server_refusal_keeps_modules_visible_while_read_only(): void
    {
        // The server answered "license_expired" (e.g. unpaid): the last token is kept.
        $this->activation->forceFill(['license_status' => 'expired', 'expired_detected_at' => now()->subDays(2)])->save();
        $this->assertSame(LicenseState::Expired, $this->state(), 'an older "active" token does not override the refusal');

        $this->activation->caches()->delete();
        app(LicenseValidationService::class)->forget();
        $this->assertContains('releases', app(LicenseValidationService::class)->snapshot()->features());
        $this->actingAs($this->admin)->get('/releases')->assertOk()->assertSee('Read-only mode');
    }

    public function test_lifetime_licenses_never_expire(): void
    {
        $this->licenseExpires(null);
        $this->travel(5)->years();

        // The token itself has expired by then (offline grace), but never the license.
        $this->assertNotContains($this->state(), [LicenseState::Expired, LicenseState::Locked]);
    }

    public function test_expiring_ribbon_on_staff_dashboards_only(): void
    {
        $this->licenseExpires(now()->toImmutable()->addDays(20));

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSee('Your license expires on')
            ->assertSee('20');

        $manager = $this->makeUser(SystemRole::LabelManager);
        $this->actingAs($manager)->get('/dashboard')->assertSee('Your license expires on');

        $artist = Artist::factory()->create();
        $artistUser = $this->makeUser(SystemRole::Artist);
        $artistUser->artists()->attach($artist->id);
        $this->actingAs($artistUser)->get('/dashboard')->assertOk()->assertDontSee('Your license expires on');

        // Not shown more than 30 days ahead.
        $this->licenseExpires(now()->toImmutable()->addDays(45));
        $this->actingAs($this->admin)->get('/dashboard')->assertDontSee('Your license expires on');
    }

    public function test_license_is_checked_every_hour_without_cron(): void
    {
        $this->assertSame(60, AutomaticTasks::TASKS['license']);
        $this->assertSame(60, (int) config('licensing.cache_duration_minutes'));
        $this->assertSame([], app(Schedule::class)->events(), 'no cron schedule');
    }
}

<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Enums\SystemRole;
use App\Exceptions\LicenseException;
use App\Exceptions\TrialLimitException;
use App\Livewire\Releases\Form as ReleaseForm;
use App\Models\Artist;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Models\User;
use App\Plugins\PluginException;
use App\Plugins\PluginManager;
use App\Services\ArtistService;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\TrackService;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseCacheService;
use App\Services\License\LicenseValidationService;
use App\Services\License\Providers\MockLicenseProvider;
use App\Services\License\TrialLimits;
use App\Services\SettingsService;
use App\Services\UserService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Free trials from MusicDesk.tech 2.0.1: limits from the signed token, banner, buy link,
 * end of trial like an expired license, conversion lifts the limits.
 */
class FreeTrialTest extends TestCase
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
        $this->activation = $this->activateLicense('TRIL');
    }

    /** Store a freshly signed token for the activation. */
    protected function token(array $plan): void
    {
        $context = ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
        $result = app(MockLicenseProvider::class)->issue($context, (string) $this->activation->activation_id, $plan + [
            'plan' => 'starter', 'status' => 'active', 'user_limit' => 3,
            'features' => ['artists', 'releases', 'tracks', 'distribution', 'reports', 'backups'],
        ], 30);
        app(LicenseActivationService::class)->applyResult($this->activation, $result);
        app(LicenseCacheService::class)->store($this->activation, $result);
        app(LicenseValidationService::class)->forget();
    }

    protected function trial(): TrialLimits
    {
        app(LicenseValidationService::class)->forget();

        return app(TrialLimits::class);
    }

    public function test_trial_and_limits_come_from_the_signed_token(): void
    {
        $trial = $this->trial();
        $this->assertTrue($trial->isTrial());
        $this->assertSame(['domains' => 1, 'users' => 2, 'artists' => 3, 'releases' => 2, 'tracks' => 4, 'addons' => false], $trial->limits());
        $this->assertSame(14, $trial->daysLeft());
        $this->assertSame('https://musicdesk.tech/checkout/starter', $trial->buyUrl());

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertSee('Free trial: 14 days left')->assertSee('https://musicdesk.tech/checkout/starter')->assertSee('Buy LabelDesk Starter');
        $this->actingAs($this->admin)->get(route('license.show'))->assertOk()
            ->assertSee('Free trial limits')->assertSee('not included in the trial');
    }

    public function test_creating_more_than_the_trial_allows_is_refused(): void
    {
        $artists = app(ArtistService::class);
        foreach (range(1, 3) as $i) {
            $artists->create($this->admin, ['name' => "Artist $i", 'type' => 'solo', 'status' => 'active']);
        }
        try {
            $artists->create($this->admin, ['name' => 'One too many', 'type' => 'solo', 'status' => 'active']);
            $this->fail('The 4th artist must be refused');
        } catch (TrialLimitException $e) {
            $this->assertStringContainsString('Your free trial allows up to 3 artists', $e->getMessage());
            $this->assertStringContainsString('checkout/starter', $e->getMessage());
        }
        $this->assertSame(3, Artist::query()->count(), 'existing artists stay usable');

        // Releases: 2, from the form too.
        $artist = Artist::query()->first();
        $releases = app(ReleaseService::class);
        $releases->create($this->admin, ['title' => 'One', 'type' => 'single'], [['artist_id' => $artist->id, 'role' => 'primary']]);
        $releases->create($this->admin, ['title' => 'Two', 'type' => 'single'], [['artist_id' => $artist->id, 'role' => 'primary']]);
        Livewire::actingAs($this->admin)->test(ReleaseForm::class)
            ->set('title', 'Three')->set('artistRows', [['artist_id' => $artist->id, 'role' => 'primary']])
            ->call('save')->assertHasErrors('form');
        $this->assertSame(2, Release::query()->count());

        // Tracks: 4.
        $release = Release::query()->first();
        $tracks = app(TrackService::class);
        foreach (range(1, 4) as $i) {
            $tracks->save($this->admin, $release, null, ['title' => "T$i", 'language_id' => null], [['artist_id' => $artist->id, 'role' => 'primary']], []);
        }
        $this->expectException(TrialLimitException::class);
        $tracks->save($this->admin, $release, null, ['title' => 'T5', 'language_id' => null], [['artist_id' => $artist->id, 'role' => 'primary']], []);
    }

    public function test_users_and_add_ons_are_limited(): void
    {
        $users = app(UserService::class);
        $this->assertSame(2, $users->seatLimit(), 'the trial limit is lower than the plan seats');
        $this->makeUser(SystemRole::Viewer);

        $this->actingAs($this->admin)->get('/users')->assertOk();
        $this->assertFalse($this->trial()->allows('users'));

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('Add-ons are not included in the free trial');
        $plugin = \App\Models\Plugin::query()->create(['slug' => 'x', 'name' => 'X', 'version' => '1.0.0', 'addon' => 'x', 'manifest' => []]);
        app(PluginManager::class)->activate($plugin, 'MD-LD-TEST-ADDN-AAAA-BBBB');
    }

    public function test_buying_the_plan_lifts_every_limit(): void
    {
        $this->token(['trial' => false, 'expires' => now()->addYear()->timestamp]);

        $trial = $this->trial();
        $this->assertFalse($trial->isTrial());
        $this->assertNull($trial->limits());
        $this->assertTrue($trial->allows('artists', 1000));
        $this->assertTrue($trial->addonsAllowed());
        $this->actingAs($this->admin)->get('/dashboard')->assertDontSee('Free trial');
    }

    public function test_an_ended_trial_is_read_only_then_locked_with_a_buy_link(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00'));
        $this->token(['trial' => true, 'limits' => ['users' => 2, 'artists' => 3, 'releases' => 2, 'tracks' => 4, 'addons' => false],
            'expires' => CarbonImmutable::parse('2026-12-10 09:00')->timestamp]);

        $this->travelTo(CarbonImmutable::parse('2026-12-08 09:00'));
        $this->assertTrue($this->trial()->ending());
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('Free trial: 2 days left')->assertSee('ld-ribbon--warning', false);

        $this->travelTo(CarbonImmutable::parse('2026-12-12 09:00'));
        app(LicenseValidationService::class)->forget();
        $this->assertSame(LicenseState::Expired->value, app(LicenseValidationService::class)->snapshot()->state->value);
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('Your free trial has ended')->assertSee('checkout/starter');

        $this->travelTo(CarbonImmutable::parse('2026-12-21 09:00'));
        app(LicenseValidationService::class)->forget();
        $this->get('/suspended')->assertStatus(403)->assertSee('Buy LabelDesk Starter')->assertSee('Free trial ended')->assertDontSee('Renew at MusicDesk.tech');
    }

    public function test_trial_used_and_deleted_license_messages(): void
    {
        $this->assertSame('This domain has already run a free trial. Buy a license to use LabelDesk on it.', LicenseException::fromApi('trial_used', null)->getMessage());
        $this->assertStringContainsString('contact MusicDesk.tech support', LicenseException::fromApi('activation_not_found', null)->getMessage());
        $this->assertStringContainsString('contact MusicDesk.tech support', LicenseException::fromApi('invalid_key', null)->getMessage());
    }

    public function test_artist_sign_up_pauses_at_the_trial_limit(): void
    {
        app(SettingsService::class)->setMany(['auth.registration' => true]);
        foreach (range(1, 3) as $i) {
            Artist::factory()->create();
        }
        auth()->logout();

        $this->post('/register', ['type' => 'artist', 'name' => 'Ana', 'artist_name' => 'Nova', 'email' => 'ana@example.com',
            'password' => 'Str0ng-pass!', 'password_confirmation' => 'Str0ng-pass!', 'confirm' => '1'])
            ->assertSessionHasErrors(['type' => 'Sign-ups for artists are paused at the moment. Please try again later.']);
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }
}

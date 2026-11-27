<?php

namespace Tests\Feature\Security;

use App\Enums\SystemRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\License\LicenseSignatureVerifier;
use App\Services\Security\SupportSsoService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MusicDesk support sign-in (MusicDesk.tech 2.0.2): a one-time, MusicDesk.tech-signed token signs in
 * the Super Admin, works whatever the licence state is, is audited, time-limited and can be switched off.
 * A licence token must never be able to sign anyone in.
 */
class SupportSsoTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['labeldesk.display_timezone' => 'UTC']);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        // The app trusts this public key offline (as it ships the real one).
        config(['licensing.testing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin, ['name' => 'Site Owner', 'email' => 'owner@example.com']);
        $this->activateLicense();
    }

    protected function token(array $overrides = [], ?string $secret = null): string
    {
        $now = now()->timestamp;
        $claims = array_merge([
            'typ' => 'admin_sso', 'iss' => 'https://musicdesk.tech', 'aud' => 'label.example.com', 'dom' => 'label.example.com',
            'iat' => $now, 'exp' => $now + 120, 'jti' => (string) Str::uuid(),
            'lic' => 'lic-7', 'act' => 'act-7', 'by' => 'Priya (MusicDesk)', 'by_email' => 'priya@musicdesk.tech', 'reason' => 'Ticket #482',
        ], $overrides);
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => '75338fabc5122988'];
        $input = LicenseSignatureVerifier::b64((string) json_encode($header)).'.'.LicenseSignatureVerifier::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $input.'.'.LicenseSignatureVerifier::b64(sodium_crypto_sign_detached($input, $secret ?? $this->secret));
    }

    public function test_a_valid_token_signs_in_the_super_admin_and_is_audited(): void
    {
        Mail::fake();
        $response = $this->post('/musicdesk/sso', ['token' => $this->token()]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame('priya@musicdesk.tech', session('musicdesk_support')['by_email']);

        $entry = ActivityLog::query()->where('event', 'security.support_signin')->sole();
        $this->assertStringContainsString('Priya (MusicDesk)', $entry->description);
        $this->assertSame('Ticket #482', data_get($entry->properties, 'reason'));
        $this->assertSame('lic-7', data_get($entry->properties, 'license'));
        Mail::assertSent(fn (\Illuminate\Mail\Mailable $m) => true);

        // last_login is not touched by a support sign-in.
        $this->assertNull($this->admin->fresh()->last_login_at);

        // The banner shows on the next page.
        $this->get('/dashboard')->assertOk()->assertSee('MusicDesk support session')->assertSee('Priya (MusicDesk)');
    }

    public function test_a_licence_token_cannot_sign_anyone_in(): void
    {
        // Same key and issuer, but not typ=admin_sso: refused.
        foreach ([['typ' => 'addon'], ['typ' => null], ['typ' => 'license']] as $override) {
            $this->post('/musicdesk/sso', ['token' => $this->token($override)])->assertStatus(403)->assertSee('not valid');
            $this->assertGuest();
        }
    }

    public function test_bad_tokens_are_refused_without_saying_why(): void
    {
        $cases = [
            'wrongsig' => $this->token([], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            'baddomain' => $this->token(['aud' => 'evil.example.com', 'dom' => 'evil.example.com']),
            'badissuer' => $this->token(['iss' => 'https://evil.example']),
            'timedout' => $this->token(['iat' => now()->timestamp - 600, 'exp' => now()->timestamp - 480]),
            'toolong' => $this->token(['exp' => now()->timestamp + 3600]),
            'nojti' => $this->token(['jti' => '']),
        ];
        foreach ($cases as $label => $token) {
            $this->post('/musicdesk/sso', ['token' => $token])->assertStatus(403)->assertDontSee($label);
            $this->assertGuest();
        }

        // Not even an empty or garbage token leaks a stack trace.
        $this->post('/musicdesk/sso', ['token' => 'not-a-token'])->assertStatus(403);
    }

    public function test_a_token_is_single_use(): void
    {
        $token = $this->token();
        $this->post('/musicdesk/sso', ['token' => $token])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $this->post('/musicdesk/sso', ['token' => $token])->assertStatus(403);
        $this->assertGuest();
    }

    public function test_get_is_not_allowed(): void
    {
        $this->get('/musicdesk/sso')->assertStatus(405);
    }

    public function test_the_setting_turns_support_sign_in_off(): void
    {
        app(SettingsService::class)->set('security.support_sso', false);
        $this->post('/musicdesk/sso', ['token' => $this->token()])->assertStatus(403)->assertSee('turned off');
        $this->assertGuest();
    }

    public function test_it_needs_an_active_super_admin(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();
        $this->post('/musicdesk/sso', ['token' => $this->token()])->assertStatus(403)->assertSee('no active Super Admin');
    }

    public function test_the_session_ends_after_sixty_minutes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00:00'));
        $this->post('/musicdesk/sso', ['token' => $this->token()])->assertRedirect(route('dashboard'));
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:59:00'));
        $this->get('/dashboard')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-27 11:00:01'));
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_email_notice_can_be_turned_off(): void
    {
        Mail::fake();
        app(SettingsService::class)->set('security.support_sso_email', false);
        $this->post('/musicdesk/sso', ['token' => $this->token()])->assertRedirect(route('dashboard'));
        Mail::assertNothingSent();
    }

    public function test_support_can_reach_the_licence_page_on_a_locked_site(): void
    {
        // Lock the site: activate, expire long ago.
        $this->activateLicense();
        $context = \App\Services\License\Data\ActivationContext::for((string) config('app.url'), self::INSTALLATION_ID);
        $activation = \App\Models\LicenseActivation::query()->firstOrFail();
        $result = app(\App\Services\License\Providers\MockLicenseProvider::class)->issue($context, (string) $activation->activation_id, [
            'plan' => 'starter', 'status' => 'active', 'user_limit' => 3, 'features' => ['artists'],
            'expires' => now()->subMonths(2)->timestamp,
        ], 30);
        app(\App\Services\License\LicenseActivationService::class)->applyResult($activation, $result);
        app(\App\Services\License\LicenseCacheService::class)->store($activation, $result);
        app(\App\Services\License\LicenseValidationService::class)->forget();

        // A guest is sent to the locked page.
        $this->get('/settings/license')->assertRedirect(route('license.locked'));

        $this->post('/musicdesk/sso', ['token' => $this->token()])->assertRedirect(route('license.show'));
        $this->assertAuthenticatedAs($this->admin);
        $this->get('/settings/license')->assertOk();
    }
}

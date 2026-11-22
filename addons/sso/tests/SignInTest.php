<?php

namespace LabelDesk\Addons\Sso\Tests;

require_once __DIR__.'/SsoTestCase.php';

use App\Enums\SystemRole;
use App\Models\ActivityLog;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginManager;
use LabelDesk\Addons\Sso\Models\SsoIdentity;
use LabelDesk\Addons\Sso\SsoAuthenticator;

class SignInTest extends SsoTestCase
{
    public function test_sign_in_page_shows_buttons_for_enabled_providers_only(): void
    {
        $this->provider(['name' => 'Contoso']);
        $this->provider(['name' => 'Hidden IdP', 'enabled' => false]);

        $this->get('/login')->assertOk()
            ->assertSee('Sign in with Contoso')
            ->assertSee(route('sso.redirect', 'contoso'), false)
            ->assertDontSee('Hidden IdP');

        // Appearance preview: rendered, but not a link.
        $html = view('auth.login', ['preview' => true])->render();
        $this->assertStringContainsString('Sign in with Contoso', $html);
        $this->assertStringNotContainsString(route('sso.redirect', 'contoso'), $html);
    }

    public function test_redirect_uses_pkce_state_and_nonce(): void
    {
        $provider = $this->provider();
        $query = $this->startSignIn($provider);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('labeldesk-client', $query['client_id']);
        $this->assertSame(route('sso.callback', $provider), $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertGreaterThanOrEqual(40, strlen($query['state']));
        $this->assertGreaterThanOrEqual(40, strlen($query['nonce']));
        $this->assertNotSame($query['state'], $query['nonce']);

        $pending = session('sso.pending');
        $this->assertSame($query['state'], $pending['state']);
        $this->assertSame(SsoTestCase::b64(hash('sha256', $pending['verifier'], true)), $query['code_challenge']);
    }

    public function test_existing_active_user_signs_in_by_verified_email(): void
    {
        $provider = $this->provider();
        $user = $this->makeUser(SystemRole::LabelManager, ['email' => 'staff@label.test']);
        $this->withSession(['probe' => 1]);
        $before = session()->getId();

        $this->signIn($provider)->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'session is regenerated');
        $this->assertNull(session('sso.pending'), 'pending sign-in is used once');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('sso_identities', ['sso_provider_id' => $provider->id, 'user_id' => $user->id, 'subject' => 'subject-1']);
        $this->assertTrue(ActivityLog::query()->where('event', 'auth.login')->where('user_id', $user->id)->exists());

        // Code exchange: PKCE verifier and client authentication, secret not in the body.
        $request = $this->tokenRequests[0];
        $this->assertSame('authorization_code', $request['grant_type']);
        $this->assertSame('auth-code-123', $request['code']);
        $this->assertNotEmpty($request['code_verifier']);
        $this->assertSame('Basic '.base64_encode('labeldesk-client:top-secret-value'), $request->header('Authorization')[0]);
        $this->assertArrayNotHasKey('client_secret', $request->data());
    }

    public function test_linked_identity_is_used_on_later_sign_ins(): void
    {
        $provider = $this->provider();
        $user = $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);
        $this->signIn($provider);
        auth()->logout();

        // The provider now reports another email: the stable subject still matches.
        $this->signIn($provider, ['email' => 'renamed@label.test'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, SsoIdentity::query()->count());
    }

    public function test_es256_tokens_are_accepted(): void
    {
        $provider = $this->provider();
        $user = $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);
        $this->tokenAlg = 'ES256';
        $this->tokenKid = 'ec-1';

        $this->signIn($provider)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_key_id_refreshes_the_key_set_once(): void
    {
        $provider = $this->provider();
        $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);
        $this->signIn($provider); // caches the key set
        auth()->logout();
        $this->travel(2)->minutes();

        // The provider rotated its key.
        $this->jwks = [SsoTestCase::rsaJwk('rsa-other.pem', 'rsa-2')];
        $query = $this->startSignIn($provider);
        $this->tokenOverride = $this->idToken(['nonce' => $query['nonce']], 'RS256', 'rsa-2', 'rsa-other.pem');
        $this->get(route('sso.callback', $provider).'?'.http_build_query(['code' => 'c', 'state' => $query['state']]))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertSame(2, $this->jwksFetches);
    }

    public function test_forged_or_mismatched_tokens_are_refused(): void
    {
        $provider = $this->provider();
        $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);

        $cases = [
            'signed by another key' => fn ($nonce) => $this->idToken(['nonce' => $nonce], 'RS256', 'rsa-1', 'rsa-other.pem'),
            'alg none' => fn ($nonce) => SsoTestCase::jwt(['alg' => 'none'], ['iss' => self::ISSUER, 'aud' => 'labeldesk-client', 'sub' => 's', 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 60, 'email' => 'staff@label.test', 'email_verified' => true], 'rsa.pem'),
            'HS256' => fn ($nonce) => SsoTestCase::jwt(['alg' => 'HS256', 'kid' => 'rsa-1'], ['iss' => self::ISSUER, 'aud' => 'labeldesk-client', 'sub' => 's', 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 60], 'rsa.pem'),
            'wrong audience' => fn ($nonce) => $this->idToken(['nonce' => $nonce, 'aud' => 'someone-else']),
            'wrong issuer' => fn ($nonce) => $this->idToken(['nonce' => $nonce, 'iss' => 'https://evil.example.test']),
            'expired' => fn ($nonce) => $this->idToken(['nonce' => $nonce, 'exp' => time() - 600, 'iat' => time() - 900]),
            'wrong nonce' => fn ($nonce) => $this->idToken(['nonce' => 'replayed-nonce']),
        ];

        foreach ($cases as $label => $token) {
            $query = $this->startSignIn($provider);
            $this->tokenOverride = $token($query['nonce']);
            $this->get(route('sso.callback', $provider).'?'.http_build_query(['code' => 'c', 'state' => $query['state']]))
                ->assertRedirect(route('login'))->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertSame(0, SsoIdentity::query()->count(), $label);
        }
        $this->assertTrue(ActivityLog::query()->where('event', 'sso.failed')->exists());
    }

    public function test_state_must_match_and_is_single_use(): void
    {
        $provider = $this->provider();
        $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);

        $query = $this->startSignIn($provider);
        $this->tokenClaims = ['nonce' => $query['nonce']];
        $this->get(route('sso.callback', $provider).'?code=c&state=forged')->assertSessionHasErrors('email');
        $this->assertGuest();

        // The forged attempt consumed the pending sign-in: the real state no longer works either.
        $this->get(route('sso.callback', $provider).'?'.http_build_query(['code' => 'c', 'state' => $query['state']]))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame([], $this->tokenRequests, 'no code is exchanged without a matching state');
    }

    public function test_provider_errors_are_shown(): void
    {
        $provider = $this->provider();
        $query = $this->startSignIn($provider);

        $this->get(route('sso.callback', $provider).'?'.http_build_query(['error' => 'access_denied', 'state' => $query['state']]))
            ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'The sign-in was cancelled.']);
    }

    public function test_unverified_email_and_foreign_domains_are_refused(): void
    {
        $provider = $this->provider();
        $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);
        $this->makeUser(SystemRole::Viewer, ['email' => 'staff@other.test']);

        $this->signIn($provider, ['email_verified' => false])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->signIn($provider, ['email' => 'staff@other.test'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Entra ID: "xms_edov" (domain owner verified) vouches for the address.
        $this->signIn($provider, ['email_verified' => null, 'xms_edov' => true])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_inactive_deleted_and_unknown_users_are_refused(): void
    {
        $provider = $this->provider();
        $inactive = $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);
        $inactive->forceFill(['is_active' => false])->save();

        $this->signIn($provider)->assertSessionHasErrors('email');
        $this->assertGuest();

        $inactive->forceFill(['is_active' => true])->save();
        $inactive->delete();
        $this->signIn($provider)->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->signIn($provider, ['email' => 'nobody@label.test', 'sub' => 'subject-2'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'nobody@label.test')->exists(), 'no account is created by default');
    }

    public function test_linked_user_who_was_deactivated_is_refused(): void
    {
        $provider = $this->provider();
        $user = $this->makeUser(SystemRole::Viewer, ['email' => 'staff@label.test']);
        $this->signIn($provider);
        auth()->logout();

        $user->forceFill(['is_active' => false])->save();
        $this->signIn($provider)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_just_in_time_accounts_get_the_chosen_role(): void
    {
        $provider = $this->provider(['jit_enabled' => true, 'jit_role' => SystemRole::Viewer->value]);

        $this->signIn($provider, ['email' => 'New.Person@Label.test', 'name' => 'New Person'])->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'new.person@label.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('New Person', $user->name);
        $this->assertTrue($user->hasRole(SystemRole::Viewer->value));
        $this->assertTrue($user->is_active);
    }

    public function test_just_in_time_never_creates_super_admins(): void
    {
        $this->assertNull(SsoAuthenticator::assignableRole(SystemRole::SuperAdmin->value));
        $this->assertNull(SsoAuthenticator::assignableRole(SystemRole::Artist->value));

        $provider = $this->provider(['jit_enabled' => true, 'jit_role' => SystemRole::SuperAdmin->value]);
        $this->signIn($provider, ['email' => 'sneaky@label.test'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'sneaky@label.test')->exists());
    }

    public function test_signed_in_users_and_disabled_providers_cannot_start_sign_in(): void
    {
        $provider = $this->provider();
        $this->actingAs($this->admin)->get(route('sso.redirect', $provider))->assertRedirect(route('dashboard'));
        auth()->logout();

        $provider->forceFill(['enabled' => false])->save();
        $this->get(route('sso.redirect', $provider))->assertNotFound();
    }

    public function test_routes_answer_404_when_the_add_on_is_deactivated(): void
    {
        $provider = $this->provider();
        app(PluginManager::class)->deactivate(Plugin::query()->where('slug', 'sso')->firstOrFail());

        $this->get('/sso/'.$provider->slug.'/redirect')->assertNotFound();
    }
}

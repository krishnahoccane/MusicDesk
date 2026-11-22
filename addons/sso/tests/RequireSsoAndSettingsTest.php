<?php

namespace LabelDesk\Addons\Sso\Tests;

require_once __DIR__.'/SsoTestCase.php';

use App\Enums\SystemRole;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LabelDesk\Addons\Sso\Livewire\Settings;
use LabelDesk\Addons\Sso\Models\SsoProvider;
use LabelDesk\Addons\Sso\SsoPolicy;
use Livewire\Livewire;

class RequireSsoAndSettingsTest extends SsoTestCase
{
    protected function requireSso(bool $artists = false): void
    {
        app(SettingsService::class)->set(SsoPolicy::REQUIRE, true, 'bool');
        app(SettingsService::class)->set(SsoPolicy::INCLUDE_ARTISTS, $artists, 'bool');
    }

    public function test_required_sso_blocks_staff_passwords_but_not_super_admins(): void
    {
        $this->provider();
        $this->requireSso();
        $staff = $this->makeUser(SystemRole::LabelManager);

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Your organisation requires single sign-on. Use a sign-in button below.']);
        $this->assertGuest();

        // A wrong password still gets the generic message (nothing is revealed).
        $this->post('/login', ['email' => $staff->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        // Break-glass: Super Admins keep password sign-in.
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_artist_accounts_are_only_included_when_chosen(): void
    {
        $this->provider();
        $this->requireSso();
        $artist = $this->makeUser(SystemRole::Artist);

        $this->post('/login', ['email' => $artist->email, 'password' => 'password'])->assertRedirect('/dashboard');
        auth()->logout();

        $this->requireSso(artists: true);
        $this->post('/login', ['email' => $artist->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_requirement_is_not_enforced_without_an_enabled_provider(): void
    {
        $this->provider(['enabled' => false]);
        $this->requireSso();
        $staff = $this->makeUser(SystemRole::Viewer);

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_settings_page_needs_settings_permission(): void
    {
        $this->actingAs($this->makeUser(SystemRole::Viewer))->get(route('sso.settings'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('sso.settings'))->assertOk()->assertSee('Identity providers')->assertSee('Single sign-on');
    }

    public function test_admin_adds_a_provider_with_an_encrypted_secret(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('create')
            ->set('preset', 'oidc')
            ->set('name', 'Example ID')
            ->set('issuer', self::ISSUER.'/.well-known/openid-configuration')
            ->set('client_id', 'labeldesk-client')
            ->set('client_secret', 'plain-secret-123')
            ->set('allowed_domains', 'Label.test, @example.org')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $provider = SsoProvider::query()->sole();
        $this->assertSame('example-id', $provider->slug);
        $this->assertSame(self::ISSUER, $provider->issuer);
        $this->assertSame(['label.test', 'example.org'], $provider->allowed_domains);
        $this->assertSame('plain-secret-123', $provider->client_secret);
        $raw = DB::table('sso_providers')->value('client_secret');
        $this->assertStringNotContainsString('plain-secret-123', $raw);
        $this->assertFalse(DB::table('activity_logs')->where('properties', 'like', '%plain-secret-123%')->exists());

        // Editing keeps the secret when the field is left blank.
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('edit', $provider->id)
            ->assertSet('client_secret', '')
            ->assertSet('hasSecret', true)
            ->set('name', 'Renamed')
            ->call('save')->assertHasNoErrors();
        $provider->refresh();
        $this->assertSame('plain-secret-123', $provider->client_secret);
        $this->assertSame('example-id', $provider->slug, 'the redirect URI does not change');
    }

    public function test_entra_preset_builds_the_tenant_issuer_and_checks_discovery(): void
    {
        $tenant = '72f988bf-86f1-41af-91ab-2d7cd011db47';
        $issuer = "https://login.microsoftonline.com/{$tenant}/v2.0";
        Http::fake([$issuer.'/.well-known/openid-configuration' => Http::response(['issuer' => 'https://login.microsoftonline.com/other/v2.0'])]);

        $component = Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('create')
            ->set('preset', 'entra')->set('name', 'Microsoft')->set('tenant', 'common')
            ->set('client_id', 'app-id')->set('client_secret', 's')
            ->call('save')->assertHasErrors('tenant');

        $component->set('tenant', $tenant)->call('save')->assertHasErrors('tenant'); // discovery reports another issuer
        $this->assertSame(0, SsoProvider::query()->count());

        $component->set('enabled', false)->call('save')->assertHasNoErrors();
        $this->assertSame($issuer, SsoProvider::query()->sole()->issuer);
    }

    public function test_just_in_time_needs_a_role_and_allowed_domains(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('create')
            ->set('preset', 'oidc')->set('name', 'Example')->set('issuer', self::ISSUER)
            ->set('client_id', 'c')->set('client_secret', 's')
            ->set('jit_enabled', true)
            ->set('jit_role', SystemRole::SuperAdmin->value)
            ->call('save')->assertHasErrors('jit_role')
            ->set('jit_role', SystemRole::Viewer->value)
            ->call('save')->assertHasErrors('allowed_domains')
            ->set('allowed_domains', 'label.test')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(SystemRole::Viewer->value, SsoProvider::query()->sole()->jit_role);
    }

    public function test_policy_needs_an_enabled_provider_and_ends_remembered_sessions(): void
    {
        $staff = $this->makeUser(SystemRole::Viewer);
        $staff->forceFill(['remember_token' => 'old-token'])->save();
        $this->admin->forceFill(['remember_token' => 'admin-token'])->save();

        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('require_sso', true)->call('savePolicy')->assertHasErrors('require_sso');

        $this->provider();
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('require_sso', true)->call('savePolicy')->assertHasNoErrors();

        $this->assertTrue(app(SsoPolicy::class)->required());
        $this->assertNotSame('old-token', $staff->fresh()->remember_token);
        $this->assertSame('admin-token', $this->admin->fresh()->remember_token);
    }

    public function test_the_last_provider_cannot_be_removed_while_sso_is_required(): void
    {
        $provider = $this->provider();
        $this->requireSso();

        Livewire::actingAs($this->admin)->test(Settings::class)->call('delete', $provider->id);
        $this->assertTrue(SsoProvider::query()->whereKey($provider->id)->exists());

        Livewire::actingAs($this->admin)->test(Settings::class)->call('toggle', $provider->id);
        $this->assertTrue($provider->fresh()->enabled);
    }

    public function test_viewers_cannot_call_settings_actions(): void
    {
        $provider = $this->provider();
        Livewire::actingAs($this->makeUser(SystemRole::Viewer))->test(Settings::class)->assertForbidden();
        $this->assertTrue(SsoProvider::query()->whereKey($provider->id)->exists());
    }
}

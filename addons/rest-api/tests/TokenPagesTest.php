<?php

namespace LabelDesk\Addons\RestApi\Tests;

use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\License\LicenseValidationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LabelDesk\Addons\RestApi\Livewire\Tokens;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Services\ApiTokenService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

/** Administration → API tokens and the API reference page. */
class TokenPagesTest extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $this->installAddon(base_path('addons/rest-api'));
    }

    protected function manager(): User
    {
        $user = $this->makeUser(SystemRole::ArtistManager);
        $user->givePermissionTo('api.tokens');

        return $user;
    }

    public function test_activation_creates_the_permissions_and_menu_item(): void
    {
        $this->assertTrue(Permission::query()->where('name', 'api.tokens')->exists());
        $this->assertTrue(Permission::query()->where('name', 'api.tokens.manage')->exists());

        $this->actingAs($this->admin)->get(route('rest-api.tokens'))
            ->assertOk()
            ->assertSee('API tokens')
            ->assertSee(route('rest-api.reference'), false);
    }

    public function test_pages_need_the_api_tokens_permission(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);

        $this->actingAs($viewer)->get(route('rest-api.tokens'))->assertForbidden();
        $this->actingAs($viewer)->get(route('rest-api.reference'))->assertForbidden();

        $viewer->givePermissionTo('api.tokens');
        $this->actingAs($viewer)->get(route('rest-api.reference'))->assertOk()->assertSee('/api/v1')->assertSee('artists:write');
    }

    public function test_a_token_is_created_and_shown_once(): void
    {
        $user = $this->manager();

        $component = Livewire::actingAs($user)->test(Tokens::class)
            ->call('create')
            ->set('name', 'Website sync')
            ->set('expiry', '30')
            ->set('access.artists', 'write')
            ->set('access.releases', 'read')
            ->set('access.tracks', 'none')
            ->set('access.contributors', 'none')
            ->call('save')
            ->assertHasNoErrors();

        $plain = $component->get('plainToken');
        $this->assertStringStartsWith('ldk_', $plain);
        $component->assertSee($plain);

        $token = ApiToken::query()->sole();
        $this->assertSame($user->id, $token->user_id);
        $this->assertSame(['artists:read', 'artists:write', 'releases:read'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertDatabaseHas('activity_logs', ['event' => 'api_token.created', 'user_id' => $user->id]);

        // The plain token is gone after dismissing and never rendered again.
        $component->call('dismissToken')->assertSet('plainToken', null)->assertDontSee($plain)->assertSee('Website sync');

        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk();
    }

    public function test_abilities_beyond_the_role_are_refused(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);
        $viewer->givePermissionTo('api.tokens');

        Livewire::actingAs($viewer)->test(Tokens::class)
            ->call('create')
            ->set('name', 'Too much')
            ->set('access.artists', 'write')
            ->call('save')
            ->assertHasErrors('access.artists');

        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_a_token_needs_a_name_and_an_ability(): void
    {
        Livewire::actingAs($this->manager())->test(Tokens::class)
            ->call('create')
            ->set('access.artists', 'none')->set('access.releases', 'none')->set('access.tracks', 'none')->set('access.contributors', 'none')
            ->call('save')
            ->assertHasErrors('name')
            ->set('name', 'Nothing')
            ->call('save')
            ->assertHasErrors('access');
    }

    public function test_users_revoke_their_own_tokens_but_not_others(): void
    {
        $owner = $this->manager();
        $other = $this->manager();
        [$token, $plain] = app(ApiTokenService::class)->create($owner, 'Mine', ['artists:read'], null);

        Livewire::actingAs($other)->test(Tokens::class)->call('revoke', $token->id)->assertForbidden();
        $this->assertNull($token->fresh()->revoked_at);

        Livewire::actingAs($owner)->test(Tokens::class)->call('revoke', $token->id)->assertDispatched('toast');
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_token_managers_see_and_revoke_every_token(): void
    {
        $owner = $this->manager();
        [$token] = app(ApiTokenService::class)->create($owner, 'Partner feed', ['artists:read'], null);

        Livewire::actingAs($this->manager())->test(Tokens::class)->assertDontSee('Partner feed')->set('scope', 'all')->assertDontSee('Partner feed');

        Livewire::actingAs($this->admin)->test(Tokens::class)
            ->set('scope', 'all')
            ->assertSee('Partner feed')
            ->assertSee($owner->email)
            ->call('revoke', $token->id);

        $this->assertSame($this->admin->id, $token->fresh()->revoked_by);
        $this->assertDatabaseHas('activity_logs', ['event' => 'api_token.revoked', 'user_id' => $this->admin->id]);
    }

    public function test_a_read_only_license_blocks_new_tokens_but_revoking_still_works(): void
    {
        $owner = $this->manager();
        [$token] = app(ApiTokenService::class)->create($owner, 'Old', ['artists:read'], null);
        LicenseActivation::query()->update(['status' => 'deactivated']);
        app(LicenseValidationService::class)->forget();

        Livewire::actingAs($owner)->test(Tokens::class)
            ->call('create')->set('name', 'New')->call('save')->assertForbidden();

        Livewire::actingAs($owner)->test(Tokens::class)->call('revoke', $token->id);
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame(1, ApiToken::query()->count());
    }

    public function test_uninstalling_revokes_all_tokens(): void
    {
        [$token] = app(ApiTokenService::class)->create($this->admin, 'Old', ['artists:read'], null);
        $manager = app(PluginManager::class);
        $plugin = Plugin::query()->where('slug', 'rest-api')->sole();

        $manager->deactivate($plugin);
        $manager->delete($plugin->fresh());

        $this->assertNotNull($token->fresh()->revoked_at);
    }
}

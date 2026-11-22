<?php

namespace LabelDesk\Addons\RestApi\Tests;

use App\Enums\SystemRole;
use App\Models\LicenseActivation;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\License\LicenseFeatureService;
use App\Services\License\LicenseService;
use App\Services\License\LicenseValidationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Services\ApiTokenService;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

/** Token authentication, scopes, license states, rate limits and the error format. */
class ApiAuthenticationTest extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $this->installAddon(base_path('addons/rest-api'));
    }

    /** @param  list<string>  $abilities */
    protected function token(User $user, array $abilities = ['artists:write', 'releases:write', 'tracks:write', 'contributors:write'], ?\DateTimeInterface $expires = null): string
    {
        [, $plain] = app(ApiTokenService::class)->create($user, 'Test', $abilities, $expires ? CarbonImmutable::instance($expires) : null);

        return $plain;
    }

    public function test_requests_without_a_token_are_refused_in_the_error_format(): void
    {
        $this->getJson('/api/v1/artists')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate')
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonStructure(['error' => ['code', 'message']]);

        $this->withToken('ldk_'.str_repeat('a', 48))->getJson('/api/v1/artists')->assertStatus(401);
        $this->withToken('not-a-token')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_a_valid_token_authenticates_and_records_use(): void
    {
        $plain = $this->token($this->admin, ['artists:read']);

        $this->withToken($plain)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->admin->id)
            ->assertJsonPath('data.token.abilities', ['artists:read'])
            ->assertJsonMissingPath('data.token.token_hash');

        $token = ApiToken::query()->sole();
        $this->assertNotNull($token->last_used_at);
        $this->assertSame('127.0.0.1', $token->last_used_ip);
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        $plain = $this->token($this->admin);
        $row = (array) DB::table('api_tokens')->first();

        $this->assertStringStartsWith('ldk_', $plain);
        $this->assertSame(52, strlen($plain));
        $this->assertSame(hash('sha256', $plain), $row['token_hash']);
        $this->assertSame(substr($plain, 0, 12), $row['token_prefix']);
        foreach ($row as $value) {
            $this->assertNotSame($plain, $value);
        }
        $this->assertStringNotContainsString($plain, (string) json_encode(DB::table('activity_logs')->get()));
    }

    public function test_expired_and_revoked_tokens_stop_working(): void
    {
        $expired = $this->token($this->admin, ['artists:read'], now()->subMinute());
        $this->withToken($expired)->getJson('/api/v1/me')->assertStatus(401);

        $plain = $this->token($this->admin, ['artists:read']);
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

        app(ApiTokenService::class)->revoke($this->admin, ApiToken::query()->latest('id')->first());
        $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_deactivated_users_and_users_without_api_access_are_refused(): void
    {
        $user = $this->makeUser(SystemRole::Viewer);
        $user->givePermissionTo('api.tokens');
        $plain = $this->token($user, ['artists:read']);
        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk();

        $user->revokePermissionTo('api.tokens');
        $this->withToken($plain)->getJson('/api/v1/artists')->assertStatus(403)->assertJsonPath('error.code', 'api_access_revoked');

        $user->givePermissionTo('api.tokens');
        $user->forceFill(['is_active' => false])->save();
        $this->withToken($plain)->getJson('/api/v1/artists')->assertStatus(401);
    }

    public function test_tokens_are_not_accepted_in_the_query_string(): void
    {
        $plain = $this->token($this->admin, ['artists:read']);

        $this->getJson('/api/v1/artists?token='.$plain)->assertStatus(401);
        $this->getJson('/api/v1/artists?api_token='.$plain)->assertStatus(401);
    }

    public function test_token_abilities_limit_what_a_token_can_do(): void
    {
        $plain = $this->token($this->admin, ['artists:read']);

        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk();
        $this->withToken($plain)->postJson('/api/v1/artists', ['name' => 'X', 'type' => 'solo', 'status' => 'active'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_ability', 'artists:write');
        $this->withToken($plain)->getJson('/api/v1/releases')->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
        $this->withToken($plain)->getJson('/api/v1/contributors')->assertStatus(403);
    }

    public function test_a_token_never_exceeds_its_owners_permissions(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);
        $viewer->givePermissionTo('api.tokens');
        $plain = $this->token($viewer, ['artists:write', 'contributors:write']);

        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk();
        $this->withToken($plain)->postJson('/api/v1/artists', ['name' => 'X', 'type' => 'solo', 'status' => 'active'])
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->withToken($plain)->postJson('/api/v1/contributors', ['name' => 'Y'])->assertStatus(403);
        $this->assertDatabaseMissing('artists', ['name' => 'X']);
    }

    public function test_read_only_license_allows_reading_but_refuses_changes(): void
    {
        $plain = $this->token($this->admin);
        // Read-only (e.g. expired within the 10-day window): plan features stay, writes are blocked.
        $this->app->instance(LicenseService::class, new class(app(LicenseValidationService::class), app(LicenseFeatureService::class)) extends LicenseService
        {
            public function allowsWrites(): bool
            {
                return false;
            }
        });

        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk();
        $this->withToken($plain)->postJson('/api/v1/artists', ['name' => 'Blocked', 'type' => 'solo', 'status' => 'active'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'license_read_only');
        $this->assertDatabaseMissing('artists', ['name' => 'Blocked']);
    }

    public function test_without_an_active_license_the_catalog_is_closed_like_in_the_app(): void
    {
        $plain = $this->token($this->admin);
        LicenseActivation::query()->update(['status' => 'deactivated']);
        app(LicenseValidationService::class)->forget();

        // An unlicensed installation has no plan features, so catalog pages are closed in the app too.
        $this->withToken($plain)->getJson('/api/v1/artists')->assertStatus(403)->assertJsonPath('error.code', 'feature_unavailable');
        $this->withToken($plain)->postJson('/api/v1/artists', ['name' => 'Blocked', 'type' => 'solo', 'status' => 'active'])->assertStatus(403);
        $this->assertDatabaseMissing('artists', ['name' => 'Blocked']);
    }

    public function test_requests_are_rate_limited_per_token(): void
    {
        config(['rest-api.rate_limit' => 2]);
        $plain = $this->token($this->admin, ['artists:read']);
        $other = $this->token($this->admin, ['artists:read']);

        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->withToken($plain)->getJson('/api/v1/artists')->assertOk();
        $this->withToken($plain)->getJson('/api/v1/artists')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'rate_limited');

        $this->withToken($other)->getJson('/api/v1/artists')->assertOk();
    }

    public function test_repeated_failed_authentication_is_throttled(): void
    {
        config(['rest-api.auth_failures_per_minute' => 3]);
        foreach (range(1, 3) as $i) {
            $this->withToken('ldk_'.str_repeat((string) $i, 48))->getJson('/api/v1/me')->assertStatus(401);
        }

        $this->withToken($this->token($this->admin, ['artists:read']))->getJson('/api/v1/me')
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_unknown_endpoints_and_ids_answer_with_the_error_format(): void
    {
        $plain = $this->token($this->admin);

        $this->withToken($plain)->getJson('/api/v1/nothing-here')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->withToken($plain)->getJson('/api/v1/artists/999999')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->withToken($plain)->putJson('/api/v1/artists/1', [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_the_api_is_gone_while_the_add_on_is_inactive(): void
    {
        $plain = $this->token($this->admin);
        $manager = app(PluginManager::class);
        $manager->deactivate(Plugin::query()->where('slug', 'rest-api')->sole());

        $this->withToken($plain)->getJson('/api/v1/me')->assertNotFound();
    }

    public function test_reference_lists_accepted_values(): void
    {
        $this->withToken($this->token($this->admin, ['artists:read']))->getJson('/api/v1/reference')
            ->assertOk()
            ->assertJsonPath('data.release_types', ['single', 'ep', 'album', 'compilation'])
            ->assertJsonStructure(['data' => ['genres', 'languages', 'artist_roles', 'contributor_roles', 'workflow_actions']]);
    }
}

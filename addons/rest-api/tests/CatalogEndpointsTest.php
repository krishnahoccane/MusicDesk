<?php

namespace LabelDesk\Addons\RestApi\Tests;

use App\Enums\SystemRole;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LabelDesk\Addons\RestApi\Services\ApiTokenService;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

/** Artists, releases, tracks and contributors through /api/v1, with the app's rules. */
class CatalogEndpointsTest extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    protected User $admin;

    protected string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $this->installAddon(base_path('addons/rest-api'));
        $this->plain = $this->token($this->admin);
    }

    /** @param  list<string>  $abilities */
    protected function token(User $user, array $abilities = ['artists:write', 'releases:write', 'tracks:write', 'contributors:write']): string
    {
        return app(ApiTokenService::class)->create($user, 'Test', $abilities, null)[1];
    }

    protected function api(?string $token = null): static
    {
        return $this->withToken($token ?? $this->plain);
    }

    protected function releaseWith(Artist $artist, array $attributes = []): Release
    {
        $release = Release::factory()->create($attributes);
        $release->artists()->attach($artist->id, ['role' => 'primary', 'position' => 1]);

        return $release;
    }

    // ------------------------------------------------------------ artists

    public function test_artists_can_be_created_listed_updated_deleted_and_restored(): void
    {
        $id = $this->api()->postJson('/api/v1/artists', [
            'name' => '  Nova Lights ', 'type' => 'band', 'status' => 'active', 'country' => 'GB', 'email' => 'Team@Nova.Example',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Nova Lights')
            ->assertJsonPath('data.email', 'team@nova.example')
            ->assertJsonPath('data.type', 'band')
            ->json('data.id');

        $this->assertDatabaseHas('activity_logs', ['event' => 'artist.created', 'user_id' => $this->admin->id]);

        Artist::factory()->create(['name' => 'Quiet Prospect', 'status' => 'prospect']);
        $this->api()->getJson('/api/v1/artists?status=active&search=nova')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);
        $this->api()->getJson('/api/v1/artists?sort=-name&per_page=1')
            ->assertOk()->assertJsonPath('data.0.name', 'Quiet Prospect')->assertJsonPath('meta.last_page', 2);

        $this->api()->patchJson("/api/v1/artists/{$id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.name', 'Nova Lights');

        $this->api()->deleteJson("/api/v1/artists/{$id}")->assertNoContent();
        $this->assertSoftDeleted('artists', ['id' => $id]);
        $this->api()->getJson('/api/v1/artists?trashed=only')->assertJsonPath('data.0.id', $id);
        $this->api()->patchJson("/api/v1/artists/{$id}", ['name' => 'Ghost'])->assertStatus(403);

        $this->api()->postJson("/api/v1/artists/{$id}/restore")->assertOk()->assertJsonPath('data.deleted_at', null);
    }

    public function test_validation_errors_list_the_fields(): void
    {
        $this->api()->postJson('/api/v1/artists', ['type' => 'orchestra', 'phone' => 'call me'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['name', 'type', 'status', 'phone']]]]);

        $this->api()->getJson('/api/v1/artists?per_page=1000')->assertStatus(422);
        $this->api()->getJson('/api/v1/artists?sort=password')->assertStatus(422);
    }

    public function test_credited_artists_cannot_be_deleted(): void
    {
        $artist = Artist::factory()->create();
        $this->releaseWith($artist);

        $this->api()->deleteJson("/api/v1/artists/{$artist->id}")
            ->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');
        $this->assertNotSoftDeleted('artists', ['id' => $artist->id]);
    }

    // ------------------------------------------------------------ releases

    public function test_releases_can_be_created_and_partially_updated(): void
    {
        $artist = Artist::factory()->create();
        $guest = Artist::factory()->create();
        $genre = Genre::query()->first();
        $language = Language::query()->first();

        $id = $this->api()->postJson('/api/v1/releases', [
            'title' => 'Glass City', 'type' => 'ep', 'upc' => '0360-0029-1452', 'release_date' => '2027-01-15',
            'genre_id' => $genre->id, 'language_id' => $language->id,
            'artists' => [['artist_id' => $artist->id, 'role' => 'primary'], ['artist_id' => $guest->id, 'role' => 'featuring']],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.upc', '036000291452')
            ->assertJsonPath('data.genre.id', $genre->id)
            ->assertJsonPath('data.artists.1.role', 'featuring')
            ->assertJsonPath('data.artist_line', $artist->name.' feat. '.$guest->name)
            ->json('data.id');

        $this->api()->patchJson("/api/v1/releases/{$id}", ['title' => 'Glass City (Deluxe)'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Glass City (Deluxe)')
            ->assertJsonCount(2, 'data.artists')
            ->assertJsonPath('data.type', 'ep');

        $this->api()->patchJson("/api/v1/releases/{$id}", ['original_release_date' => '2027-02-01'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['original_release_date']]]]);

        $this->api()->postJson('/api/v1/releases', ['title' => 'Dup', 'type' => 'single', 'upc' => '036000291452', 'artists' => [['artist_id' => $artist->id, 'role' => 'primary']]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['upc']]]]);

        $this->api()->postJson('/api/v1/releases', ['title' => 'No primary', 'type' => 'single', 'artists' => [['artist_id' => $guest->id, 'role' => 'featuring']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');

        $this->api()->getJson('/api/v1/releases?artist_id='.$guest->id)->assertJsonPath('meta.total', 1);
        $this->api()->getJson("/api/v1/releases/{$id}")->assertOk()->assertJsonPath('data.workflow.available_actions', ['submit']);
    }

    public function test_locked_releases_cannot_be_changed_and_workflow_rules_apply(): void
    {
        $artist = Artist::factory()->create();
        $release = $this->releaseWith($artist);

        // Submitting an incomplete release fails the checklist, exactly like the app.
        $this->api()->postJson("/api/v1/releases/{$release->id}/workflow", ['action' => 'submit'])
            ->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');

        $this->api()->postJson("/api/v1/releases/{$release->id}/workflow", ['action' => 'approve'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'transition_not_allowed')
            ->assertJsonPath('error.details.status', 'draft');

        $release->forceFill(['status' => 'submitted'])->save();
        $this->api()->patchJson("/api/v1/releases/{$release->id}", ['title' => 'Changed'])->assertStatus(403);
        $this->api()->deleteJson("/api/v1/releases/{$release->id}")->assertStatus(403);

        $this->api()->postJson("/api/v1/releases/{$release->id}/workflow", ['action' => 'withdraw'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('release_status_histories', ['release_id' => $release->id, 'to_status' => 'draft', 'user_id' => $this->admin->id]);
    }

    public function test_reviewer_steps_need_the_review_permission(): void
    {
        $manager = $this->makeUser(SystemRole::ArtistManager);
        $manager->givePermissionTo('api.tokens');
        $release = $this->releaseWith(Artist::factory()->create(), ['status' => 'submitted']);

        $this->api($this->token($manager))->postJson("/api/v1/releases/{$release->id}/workflow", ['action' => 'start_review'])
            ->assertStatus(409)->assertJsonPath('error.details.available_actions', ['withdraw']);
        $this->assertSame('submitted', $release->fresh()->status->value);
    }

    // ------------------------------------------------------------ tracks

    public function test_tracks_can_be_added_updated_listed_and_deleted(): void
    {
        $artist = Artist::factory()->create();
        $release = $this->releaseWith($artist);
        $writer = Contributor::query()->create(['name' => 'Asha Writer']);

        $id = $this->api()->postJson("/api/v1/releases/{$release->id}/tracks", [
            'title' => 'Opening', 'isrc' => 'GB-AYE-26-00001', 'duration_seconds' => 215, 'explicit' => true,
            'credits' => [['contributor_id' => $writer->id, 'role' => 'songwriter', 'share' => 100]],
        ])->assertCreated()
            ->assertJsonPath('data.isrc', 'GBAYE2600001')
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.artists.0.id', $artist->id)
            ->assertJsonPath('data.credits.0.name', 'Asha Writer')
            ->assertJsonPath('data.credits.0.share', '100')
            ->json('data.id');
        $this->assertTrue($release->fresh()->explicit, 'the release follows explicit tracks');

        $this->api()->patchJson("/api/v1/tracks/{$id}", ['version' => 'Radio Edit'])
            ->assertOk()
            ->assertJsonPath('data.display_title', 'Opening (Radio Edit)')
            ->assertJsonPath('data.isrc', 'GBAYE2600001')
            ->assertJsonPath('data.duration_seconds', 215)
            ->assertJsonCount(1, 'data.credits');

        // Invalid ISRCs are refused. (A duplicate ISRC hits a core bug in strict mode, see README "Open points".)
        $this->api()->postJson("/api/v1/releases/{$release->id}/tracks", ['title' => 'Bad', 'isrc' => 'GB-12'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['isrc']]]]);

        $this->api()->patchJson("/api/v1/tracks/{$id}", ['credits' => [
            ['contributor_id' => $writer->id, 'role' => 'composer', 'share' => 60],
            ['contributor_id' => $writer->id, 'role' => 'lyricist', 'share' => 120],
        ]])->assertStatus(422);

        $this->api()->getJson("/api/v1/releases/{$release->id}/tracks")->assertOk()->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/v1/tracks?isrc=GB-AYE-26-00001')->assertJsonPath('data.0.id', $id);

        $this->api()->deleteJson("/api/v1/tracks/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('tracks', ['id' => $id]);
    }

    public function test_tracks_of_locked_releases_cannot_be_changed(): void
    {
        $release = $this->releaseWith(Artist::factory()->create(), ['status' => 'approved']);
        $track = $release->tracks()->create(['title' => 'Locked', 'position' => 1]);

        $this->api()->patchJson("/api/v1/tracks/{$track->id}", ['title' => 'Changed'])->assertStatus(403);
        $this->api()->postJson("/api/v1/releases/{$release->id}/tracks", ['title' => 'New'])->assertStatus(403);
        $this->api()->deleteJson("/api/v1/tracks/{$track->id}")->assertStatus(403);
        $this->assertSame('Locked', $track->fresh()->title);
    }

    // ------------------------------------------------------------ contributors

    public function test_contributors_can_be_managed(): void
    {
        $id = $this->api()->postJson('/api/v1/contributors', ['name' => 'Mira Holt', 'ipi_number' => ' 00012345678 ', 'pro' => 'PRS'])
            ->assertCreated()->assertJsonPath('data.ipi_number', '00012345678')->json('data.id');

        $this->api()->patchJson("/api/v1/contributors/{$id}", ['email' => 'MIRA@example.com'])
            ->assertOk()->assertJsonPath('data.email', 'mira@example.com')->assertJsonPath('data.pro', 'PRS');

        $this->api()->getJson('/api/v1/contributors?search=mira')->assertJsonPath('data.0.id', $id);

        $release = $this->releaseWith(Artist::factory()->create());
        $track = $release->tracks()->create(['title' => 'Credited', 'position' => 1]);
        $track->credits()->create(['contributor_id' => $id, 'role' => 'composer']);

        $this->api()->deleteJson("/api/v1/contributors/{$id}")->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');
        $track->credits()->delete();
        $this->api()->deleteJson("/api/v1/contributors/{$id}")->assertNoContent();
    }

    // ------------------------------------------------------------ artist accounts

    public function test_artist_accounts_only_reach_their_own_artists(): void
    {
        $own = Artist::factory()->create(['name' => 'Own Artist']);
        $other = Artist::factory()->create(['name' => 'Secret Signing']);
        $ownRelease = $this->releaseWith($own, ['title' => 'Own Release']);
        $otherRelease = $this->releaseWith($other, ['title' => 'Unannounced']);
        $hiddenTrack = $otherRelease->tracks()->create(['title' => 'Hidden', 'position' => 1]);

        $artistUser = $this->makeUser(SystemRole::Artist);
        $artistUser->artists()->attach($own->id);
        $artistUser->givePermissionTo('api.tokens');
        $token = $this->token($artistUser);

        $this->api($token)->getJson('/api/v1/me')->assertJsonPath('data.user.artist_account', true);
        $this->api($token)->getJson('/api/v1/artists')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id);
        $this->api($token)->getJson("/api/v1/artists/{$other->id}")->assertNotFound();
        $this->api($token)->getJson('/api/v1/releases')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ownRelease->id);
        $this->api($token)->getJson("/api/v1/releases/{$otherRelease->id}")->assertNotFound();
        $this->api($token)->getJson("/api/v1/tracks/{$hiddenTrack->id}")->assertNotFound();
        $this->api($token)->patchJson("/api/v1/releases/{$otherRelease->id}", ['title' => 'Hacked'])->assertNotFound();

        // Artist accounts may only credit their own artists.
        $this->api($token)->postJson('/api/v1/releases', ['title' => 'Sneaky', 'type' => 'single', 'artists' => [['artist_id' => $other->id, 'role' => 'primary']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'rule_violation');

        // …and cannot create artists (no artists.create permission).
        $this->api($token)->postJson('/api/v1/artists', ['name' => 'New', 'type' => 'solo', 'status' => 'active'])->assertStatus(403);

        $this->assertSame('Unannounced', $otherRelease->fresh()->title);
        $this->assertDatabaseMissing('releases', ['title' => 'Sneaky']);
        $this->assertSame(1, Track::query()->withoutGlobalScopes()->count());
    }
}

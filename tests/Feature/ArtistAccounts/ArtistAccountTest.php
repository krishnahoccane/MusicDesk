<?php

namespace Tests\Feature\ArtistAccounts;

use App\Enums\SystemRole;
use App\Livewire\GlobalSearch;
use App\Livewire\Releases\Form as ReleaseForm;
use App\Livewire\Users\Form as UserForm;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Models\Contributor;
use App\Models\Release;
use App\Models\RoyaltyPeriod;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArtistAccountTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $artistUser;

    protected Artist $own;

    protected Artist $other;

    protected Release $ownRelease;

    protected Release $otherRelease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();

        $this->own = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->other = Artist::factory()->create(['name' => 'Secret Signing']);

        $this->ownRelease = Release::factory()->create(['title' => 'Glass City']);
        $this->ownRelease->artists()->attach($this->own->id, ['role' => 'primary', 'position' => 1]);
        $this->ownRelease->tracks()->create(['title' => 'Glass City', 'isrc' => 'GBAYE2600001', 'position' => 1]);

        $this->otherRelease = Release::factory()->create(['title' => 'Unannounced Album', 'upc' => '036000291452']);
        $this->otherRelease->artists()->attach($this->other->id, ['role' => 'primary', 'position' => 1]);
        $this->otherRelease->tracks()->create(['title' => 'Hidden Track', 'isrc' => 'GBAYE2600002', 'position' => 1]);

        $this->other->contracts()->create(['title' => 'Secret deal', 'type' => 'recording', 'status' => 'active', 'start_date' => '2026-01-01']);
        ArtistLedgerEntry::query()->forceCreate(['artist_id' => $this->other->id, 'type' => 'earnings', 'amount' => '999', 'entry_date' => '2026-10-01', 'description' => 'x']);
        ArtistLedgerEntry::query()->forceCreate(['artist_id' => $this->own->id, 'type' => 'earnings', 'amount' => '120', 'entry_date' => '2026-10-01', 'description' => 'x']);

        $this->artistUser = $this->makeUser(SystemRole::Artist, ['name' => 'Asha Nova']);
        $this->artistUser->artists()->attach($this->own->id);
    }

    public function test_admin_creates_an_artist_account_linked_to_profiles(): void
    {
        Livewire::actingAs($this->admin)->test(UserForm::class)
            ->set('name', 'Riya')->set('email', 'riya@example.com')
            ->set('role', SystemRole::Artist->value)
            ->set('send_link', false)->set('password', 'Str0ng-Passw0rd!')->set('password_confirmation', 'Str0ng-Passw0rd!')
            ->call('save')
            ->assertHasErrors('artist_ids')
            ->set('artist_ids', [(string) $this->own->id])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'riya@example.com')->sole();
        $this->assertTrue($user->isArtistAccount());
        $this->assertSame([$this->own->id], $user->linkedArtistIds());
    }

    public function test_artist_account_only_sees_its_own_catalogue(): void
    {
        $this->actingAs($this->artistUser);

        $this->get('/artists')->assertOk()->assertSee('Nova Lights')->assertDontSee('Secret Signing');
        $this->get('/releases')->assertOk()->assertSee('Glass City')->assertDontSee('Unannounced Album');
        $this->get('/tracks')->assertOk()->assertDontSee('Hidden Track');

        $this->get('/artists/'.$this->own->id)->assertOk();
        $this->get('/artists/'.$this->other->id)->assertNotFound();
        $this->get('/releases/'.$this->otherRelease->id)->assertNotFound();
        $this->get('/tracks/'.$this->otherRelease->tracks()->withoutGlobalScopes()->first()->id)->assertNotFound();
        $this->get('/contracts')->assertOk()->assertDontSee('Secret deal');

        $this->assertSame(1, Artist::query()->count());
        $this->assertSame(1, Release::query()->count());
        $this->assertSame(1, Track::query()->count());
        $this->assertSame(1, ArtistLedgerEntry::query()->count());

        Livewire::test(GlobalSearch::class)->set('query', 'Se')->assertDontSee('Secret Signing');
        Livewire::test(GlobalSearch::class)->set('query', 'Hidden')->assertDontSee('Hidden Track');

        $this->get('/dashboard')->assertOk()->assertSee('Your balance')->assertDontSee('999');
    }

    public function test_artist_account_cannot_open_staff_areas(): void
    {
        $this->actingAs($this->artistUser);

        foreach (['/users', '/roles', '/royalties', '/reports', '/settings', '/backups', '/system', '/activity', '/artists/create', '/reports/import'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_artist_account_statements_only_for_own_artists(): void
    {
        $period = RoyaltyPeriod::query()->create(['name' => '2026 Q3', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']);
        $this->actingAs($this->artistUser);

        $this->get("/royalties/periods/{$period->id}/statements/{$this->own->id}")->assertOk();
        $this->get("/royalties/periods/{$period->id}/statements/{$this->other->id}")->assertNotFound();
    }

    public function test_artist_account_creates_releases_only_for_own_artists(): void
    {
        Livewire::actingAs($this->artistUser)->test(ReleaseForm::class)
            ->assertSet('artistRows.0.artist_id', $this->own->id)
            ->set('title', 'Sneaky')
            ->set('artistRows', [['artist_id' => (string) $this->other->id, 'role' => 'primary']])
            ->call('save');
        $this->assertFalse(Release::query()->withoutGlobalScopes()->where('title', 'Sneaky')->exists());

        // A UPC used by someone else's release is refused without revealing it.
        Livewire::actingAs($this->artistUser)->test(ReleaseForm::class)
            ->set('title', 'Dup UPC')
            ->set('artistRows', [['artist_id' => (string) $this->own->id, 'role' => 'primary']])
            ->set('upc', '036000291452')
            ->call('save')
            ->assertHasErrors('upc');

        Livewire::actingAs($this->artistUser)->test(ReleaseForm::class)
            ->set('title', 'New Single')
            ->set('artistRows', [['artist_id' => (string) $this->own->id, 'role' => 'primary']])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue(Release::query()->withoutGlobalScopes()->where('title', 'New Single')->exists());
    }

    public function test_contributors_are_limited_to_own_credits(): void
    {
        $mine = Contributor::query()->create(['name' => 'Own Writer']);
        $theirs = Contributor::query()->create(['name' => 'Other Writer']);
        $this->ownRelease->tracks()->first()->credits()->create(['contributor_id' => $mine->id, 'role' => 'songwriter']);
        Track::query()->where('title', 'Hidden Track')->first()->credits()->create(['contributor_id' => $theirs->id, 'role' => 'songwriter']);

        $this->actingAs($this->artistUser);
        $this->assertSame(['Own Writer'], Contributor::query()->pluck('name')->all());
    }

    public function test_artist_role_cannot_be_edited_and_staff_are_unaffected(): void
    {
        $role = Role::findByName(SystemRole::Artist->value);
        $this->assertFalse($this->admin->can('update', $role));

        $this->actingAs($this->admin);
        $this->assertSame(2, Artist::query()->count());
        $this->assertSame(2, Release::query()->count());
    }
}

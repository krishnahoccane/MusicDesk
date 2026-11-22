<?php

namespace Tests\Feature\Artists;

use App\Enums\SystemRole;
use App\Livewire\Artists\Form;
use App\Livewire\Artists\Index;
use App\Livewire\Artists\Show;
use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\License\LicenseValidationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ArtistManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        Storage::fake('local');
    }

    public function test_permission_boundaries_on_pages(): void
    {
        $artist = Artist::factory()->create();
        $viewer = $this->makeUser(SystemRole::Viewer);
        $artistManager = $this->makeUser(SystemRole::ArtistManager);
        $accountant = $this->makeUser(SystemRole::Accountant);

        $this->actingAs($viewer)->get('/artists')->assertOk()->assertSee($artist->name)->assertDontSee('Add artist');
        $this->actingAs($viewer)->get("/artists/{$artist->id}")->assertOk();
        $this->actingAs($viewer)->get('/artists/create')->assertForbidden();
        $this->actingAs($viewer)->get("/artists/{$artist->id}/edit")->assertForbidden();

        $this->actingAs($artistManager)->get('/artists/create')->assertOk();
        $this->actingAs($artistManager)->get("/artists/{$artist->id}/edit")->assertOk();

        // Artist Managers cannot delete artists (no artists.delete).
        Livewire::actingAs($artistManager)->test(Index::class)->call('delete', $artist->id)->assertForbidden();
        $this->assertNotSoftDeleted($artist);

        $this->actingAs($accountant)->get('/artists')->assertOk();
        $this->actingAs($accountant)->get('/artists/create')->assertForbidden();
    }

    public function test_artist_can_be_created_with_a_photo(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Nova Lights')
            ->set('legal_name', 'Nova Lightfoot')
            ->set('type', 'band')
            ->set('status', 'active')
            ->set('country', 'IN')
            ->set('primary_genre', 'Indie pop')
            ->set('email', 'Nova@Example.com')
            ->set('photo', UploadedFile::fake()->image('nova.png', 1600, 1200))
            ->call('save')
            ->assertHasNoErrors();

        $artist = Artist::query()->where('name', 'Nova Lights')->firstOrFail();
        $this->assertSame('nova@example.com', $artist->email);
        $this->assertSame('India', $artist->country_name);
        $this->assertSame($this->admin->id, $artist->created_by);
        $this->assertNotNull($artist->photo_path);
        Storage::disk('local')->assertExists($artist->photo_path);

        // Re-encoded to a JPEG no larger than 1000px.
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('local')->get($artist->photo_path));
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertLessThanOrEqual(1000, max($w, $h));

        $this->assertDatabaseHas('activity_logs', ['event' => 'artist.created', 'subject_id' => $artist->id]);

        // The photo is served only through the authorised route.
        $this->actingAs($this->admin)->get(route('artists.photo', $artist))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->makeUser(SystemRole::Viewer))->get(route('artists.photo', $artist))->assertOk();
        auth()->logout();
        $this->get(route('artists.photo', $artist))->assertRedirect(route('login'));
    }

    public function test_validation(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', '')
            ->set('type', 'robot')
            ->set('country', 'ZZ')
            ->set('email', 'not-an-email')
            ->set('phone', 'call me<script>')
            ->call('save')
            ->assertHasErrors(['name', 'type', 'country', 'email', 'phone']);

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'X')
            ->set('photo', UploadedFile::fake()->create('virus.php', 10, 'image/png'))
            ->assertHasErrors('photo');
    }

    public function test_update_is_logged_with_changes(): void
    {
        $artist = Artist::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($this->admin)->test(Form::class, ['artist' => $artist])
            ->set('name', 'New Name')
            ->set('bio', 'A long biography.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('artists.show', $artist));

        $this->assertSame('New Name', $artist->fresh()->name);
        $log = ActivityLog::query()->where('event', 'artist.updated')->firstOrFail();
        $this->assertSame(['Old Name', 'New Name'], $log->properties['name']);
        $this->assertSame('changed', $log->properties['bio']);
    }

    public function test_delete_and_restore(): void
    {
        $artist = Artist::factory()->create();

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $artist->id);
        $this->assertSoftDeleted($artist);

        // Deleted artists stay viewable (read-only) and can be restored.
        $this->actingAs($this->admin)->get("/artists/{$artist->id}")->assertOk()->assertSee('This artist has been deleted.');
        $this->actingAs($this->admin)->get("/artists/{$artist->id}/edit")->assertNotFound();

        Livewire::actingAs($this->admin)->test(Show::class, ['artist' => Artist::withTrashed()->find($artist->id)])->call('restore');
        $this->assertNotSoftDeleted($artist);
        $this->assertDatabaseHas('activity_logs', ['event' => 'artist.restored']);
    }

    public function test_search_and_filters(): void
    {
        Artist::factory()->create(['name' => 'Aurora Vale', 'country' => 'NO', 'status' => 'active']);
        Artist::factory()->create(['name' => 'Basement Kings', 'type' => 'band', 'country' => 'US', 'status' => 'prospect']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertSee('Aurora Vale')->assertSee('Basement Kings')
            ->set('search', 'aurora')->assertSee('Aurora Vale')->assertDontSee('Basement Kings')
            ->set('search', '')->set('type', 'band')->assertSee('Basement Kings')->assertDontSee('Aurora Vale')
            ->set('type', '')->set('status', 'prospect')->assertSee('Basement Kings')->assertDontSee('Aurora Vale')
            ->set('status', '')->set('country', 'NO')->assertSee('Aurora Vale')->assertDontSee('Basement Kings');
    }

    public function test_feature_gating_and_read_only_mode(): void
    {
        $artist = Artist::factory()->create();

        // Without an activated license the artist module is unavailable.
        LicenseActivation::query()->update(['status' => 'deactivated']);
        app(LicenseValidationService::class)->forget();
        $this->actingAs($this->admin)->get('/artists')->assertForbidden()->assertSee('not included');

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $artist->id)->assertForbidden();
        $this->assertNotSoftDeleted($artist);
    }
}

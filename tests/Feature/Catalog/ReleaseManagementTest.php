<?php

namespace Tests\Feature\Catalog;

use App\Enums\SystemRole;
use App\Livewire\Artists\Index as ArtistIndex;
use App\Livewire\Releases\Form;
use App\Livewire\Releases\Index;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Models\User;
use App\Services\License\LicenseValidationService;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class ReleaseManagementTest extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $admin;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $this->artist = Artist::factory()->create(['name' => 'Nova Lights']);
        Storage::fake('local');
        config(['labeldesk.catalog.artwork_min_px' => 600]);
    }

    protected function tearDown(): void
    {
        $this->cleanUploads();
        parent::tearDown();
    }

    public function test_reference_data_is_seeded_idempotently(): void
    {
        $this->seed(CatalogReferenceSeeder::class);
        $this->assertSame(count(CatalogReferenceSeeder::GENRES), Genre::query()->count());
        $this->assertSame(count(CatalogReferenceSeeder::LANGUAGES), Language::query()->count());
    }

    public function test_permission_boundaries_on_pages(): void
    {
        $release = Release::factory()->create();
        $viewer = $this->makeUser(SystemRole::Viewer);
        $accountant = $this->makeUser(SystemRole::Accountant);
        $artistManager = $this->makeUser(SystemRole::ArtistManager);

        $this->actingAs($viewer)->get('/releases')->assertOk()->assertSee($release->title)->assertDontSee('New release');
        $this->actingAs($viewer)->get("/releases/{$release->id}")->assertOk();
        $this->actingAs($viewer)->get('/releases/create')->assertForbidden();
        $this->actingAs($accountant)->get("/releases/{$release->id}/edit")->assertForbidden();
        $this->actingAs($artistManager)->get('/releases/create')->assertOk();
        $this->actingAs($artistManager)->get("/releases/{$release->id}/edit")->assertOk();

        // Artist Managers cannot delete releases.
        Livewire::actingAs($artistManager)->test(Index::class)->call('delete', $release->id)->assertForbidden();
    }

    public function test_release_is_created_with_artists_and_artwork(): void
    {
        $featured = Artist::factory()->create(['name' => 'Kai Feat']);

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('title', 'Midnight Static')
            ->set('type', 'ep')
            ->set('artistRows', [
                ['artist_id' => $this->artist->id, 'role' => 'primary'],
                ['artist_id' => $featured->id, 'role' => 'featuring'],
            ])
            ->set('release_date', '2027-01-15')
            ->set('upc', '0 36000 29145 2')
            ->set('artwork', UploadedFile::fake()->image('cover.png', 800, 800))
            ->call('save')
            ->assertHasNoErrors();

        $release = Release::query()->where('title', 'Midnight Static')->firstOrFail();
        $this->assertSame('draft', $release->status->value);
        $this->assertSame('036000291452', $release->upc);
        $this->assertSame('Nova Lights feat. Kai Feat', $release->artistLine());
        $this->assertSame('2026 '.config('app.name'), $release->c_line);
        Storage::disk('local')->assertExists($release->artwork_path);
        Storage::disk('local')->assertExists($release->artwork_thumb_path);
        $this->assertDatabaseHas('activity_logs', ['event' => 'release.created']);

        $this->actingAs($this->admin)->get(route('releases.artwork', $release))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_release_validation(): void
    {
        Release::factory()->create(['upc' => '036000291452', 'catalog_number' => 'SR001']);

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('title', '')
            ->set('artistRows', [['artist_id' => '', 'role' => 'primary']])
            ->set('upc', '036000291453')
            ->set('catalog_number', 'SR001')
            ->set('release_date', '2027-01-01')
            ->set('original_release_date', '2027-06-01')
            ->call('save')
            ->assertHasErrors(['title', 'artistRows.0.artist_id', 'upc', 'catalog_number', 'original_release_date']);

        // A valid UPC already used elsewhere is refused.
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('title', 'Dup')
            ->set('artistRows', [['artist_id' => $this->artist->id, 'role' => 'primary']])
            ->set('upc', '036000291452')
            ->call('save')
            ->assertHasErrors('upc');

        // Only featured artists: a primary artist is required.
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('title', 'No primary')
            ->set('artistRows', [['artist_id' => $this->artist->id, 'role' => 'featuring']])
            ->call('save')
            ->assertHasErrors('form');
    }

    public function test_artwork_must_be_square_and_large_enough(): void
    {
        $release = Release::factory()->create();
        $release->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);

        Livewire::actingAs($this->admin)->test(Form::class, ['release' => $release])
            ->set('artwork', UploadedFile::fake()->image('wide.png', 1200, 800))
            ->call('save');
        $this->assertNull($release->fresh()->artwork_path);
        $this->assertSame(__('Saved, but the artwork was not accepted: :reason', ['reason' => 'Artwork must be square (this image is 1200 × 800 px).']), session('warning'));

        Livewire::actingAs($this->admin)->test(Form::class, ['release' => $release])
            ->set('artwork', UploadedFile::fake()->image('small.png', 300, 300))
            ->call('save');
        $this->assertNull($release->fresh()->artwork_path);
    }

    public function test_delete_restore_and_artist_protection(): void
    {
        $release = Release::factory()->create();
        $release->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);

        // A credited artist cannot be deleted.
        Livewire::actingAs($this->admin)->test(ArtistIndex::class)->call('delete', $this->artist->id)->assertDispatched('toast', type: 'danger');
        $this->assertNotSoftDeleted($this->artist);

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $release->id);
        $this->assertSoftDeleted($release);

        Livewire::actingAs($this->admin)->test(Index::class)->call('restore', $release->id);
        $this->assertNotSoftDeleted($release);
    }

    public function test_search_and_filters(): void
    {
        $a = Release::factory()->create(['title' => 'Aurora Nights', 'type' => 'album', 'upc' => '4006381333931']);
        $a->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);
        Release::factory()->create(['title' => 'Basement Tapes', 'type' => 'single']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('search', '4006381333931')->assertSee('Aurora Nights')->assertDontSee('Basement Tapes')
            ->set('search', 'nova lights')->assertSee('Aurora Nights')->assertDontSee('Basement Tapes')
            ->set('search', '')->set('type', 'single')->assertSee('Basement Tapes')->assertDontSee('Aurora Nights');
    }

    public function test_module_requires_the_license_feature(): void
    {
        LicenseActivation::query()->update(['status' => 'deactivated']);
        app(LicenseValidationService::class)->forget();

        $this->actingAs($this->admin)->get('/releases')->assertForbidden();
        $this->actingAs($this->admin)->get('/tracks')->assertForbidden();
        $this->actingAs($this->admin)->get('/contributors')->assertForbidden();
    }
}

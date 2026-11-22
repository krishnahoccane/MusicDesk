<?php

namespace Tests\Feature\BulkUpload;

use App\Enums\SystemRole;
use App\Livewire\BulkUpload\Index;
use App\Livewire\Reports\SalesImports;
use App\Models\Artist;
use App\Models\BulkUpload;
use App\Models\Release;
use App\Models\RoyaltyPeriod;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Livewire\Livewire;
use Tests\TestCase;

class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** @var list<string> */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function csv(array $rows): File
    {
        $path = storage_path('framework/testing/bulk-'.uniqid('', true).'.csv');
        @mkdir(dirname($path), 0777, true);
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $this->files[] = $path;

        return new File('upload.csv', fopen($path, 'r'));
    }

    public function test_artists_are_created_and_duplicates_skipped(): void
    {
        Artist::factory()->create(['name' => 'Existing Band']);

        $file = $this->csv([
            ['Name *', 'Type', 'Country', 'Email', 'Spotify'],
            ['Nova Lights', 'band', 'IN', 'nova@example.com', 'https://open.spotify.com/artist/1'],
            ['Existing Band', 'band', '', '', ''],
            ['', 'solo', '', '', ''],
            ['Bad Type', 'orchestra', 'XX', 'not-an-email', ''],
        ]);

        Livewire::actingAs($this->admin)->test(Index::class, ['type' => 'artists'])
            ->set('type', 'artists')
            ->set('file', $file)
            ->call('check')
            ->assertSet('preview.count', 1)
            ->assertSet('preview.errorCount', 2)
            ->call('import');

        $nova = Artist::query()->where('name', 'Nova Lights')->sole();
        $this->assertSame('IN', $nova->country);
        $this->assertSame(1, $nova->links()->count());
        $this->assertSame(1, Artist::query()->where('name', 'Existing Band')->count());

        $log = BulkUpload::query()->sole();
        $this->assertSame(1, $log->created_count);
        $this->assertSame(4, $log->total_rows);
    }

    public function test_releases_with_tracks_are_grouped_and_validated(): void
    {
        Artist::factory()->create(['name' => 'Nova Lights']);

        $file = $this->csv([
            ['release_title', 'release_type', 'release_artists', 'upc', 'release_date', 'genre', 'language', 'track_title', 'isrc', 'duration', 'explicit'],
            ['Glass City', 'ep', 'Nova Lights', '036000291452', '2027-01-15', 'Pop', 'en', 'Intro', 'GBAYE2600001', '1:05', 'no'],
            ['Glass City', 'ep', 'Nova Lights', '036000291452', '2027-01-15', 'Pop', 'en', 'Glass City', 'GBAYE2600002', '3:25', 'yes'],
            ['Broken', 'single', 'Nova Lights', '123', '', '', '', 'Bad', 'XX', 'abc', ''],
            ['Unknown Artist Single', 'single', 'Ghost Writer', '', '', '', '', 'Boo', '', '', ''],
        ]);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('type', 'releases')
            ->set('file', $file)
            ->call('check')
            ->assertSet('preview.count', 1)
            ->call('import');

        $release = Release::query()->where('title', 'Glass City')->sole();
        $this->assertSame('036000291452', $release->upc);
        $this->assertSame(2, $release->tracks()->count());
        $this->assertSame(205, $release->tracks()->where('title', 'Glass City')->value('duration_seconds'));
        $this->assertTrue((bool) $release->tracks()->where('title', 'Glass City')->value('explicit'));
        $this->assertSame('draft', $release->status->value);
        $this->assertFalse(Release::query()->where('title', 'Broken')->exists());
        $this->assertFalse(Artist::query()->where('name', 'Ghost Writer')->exists());

        // With "create missing artists" the unknown artist is created.
        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('type', 'releases')
            ->set('file', $this->csv([
                ['release_title', 'release_artists', 'track_title'],
                ['Unknown Artist Single', 'Ghost Writer', 'Boo'],
            ]))
            ->set('createMissing', true)
            ->call('check')
            ->assertSet('preview.missing', ['Ghost Writer'])
            ->call('import');
        $this->assertTrue(Release::query()->where('title', 'Unknown Artist Single')->exists());
        $this->assertTrue(Artist::query()->where('name', 'Ghost Writer')->exists());
    }

    public function test_access_follows_the_admin_switches(): void
    {
        $manager = $this->makeUser(SystemRole::LabelManager);
        $viewer = $this->makeUser(SystemRole::Viewer);
        $artist = Artist::factory()->create(['name' => 'Nova Lights']);
        $artistUser = $this->makeUser(SystemRole::Artist);
        $artistUser->artists()->attach($artist->id);

        $this->actingAs($manager)->get('/bulk-upload')->assertOk();
        $this->actingAs($viewer)->get('/bulk-upload')->assertForbidden();
        $this->actingAs($artistUser)->get('/bulk-upload')->assertForbidden(); // artists are off by default

        app(SettingsService::class)->setMany(['bulk_upload.staff' => false, 'bulk_upload.artists' => true]);

        $this->actingAs($manager)->get('/bulk-upload')->assertForbidden();
        $this->actingAs($this->admin)->get('/bulk-upload')->assertOk(); // administrators always
        $this->actingAs($artistUser)->get('/bulk-upload')->assertOk()->assertDontSee('Artist profiles');
        $this->actingAs($artistUser)->get('/bulk-upload/template/artists/csv')->assertForbidden();
        $this->actingAs($artistUser)->get('/bulk-upload/template/releases/xlsx')->assertOk();
    }

    public function test_artist_accounts_can_only_upload_for_their_own_artists(): void
    {
        app(SettingsService::class)->set('bulk_upload.artists', true);
        $own = Artist::factory()->create(['name' => 'Nova Lights']);
        Artist::factory()->create(['name' => 'Secret Signing']);
        $artistUser = $this->makeUser(SystemRole::Artist);
        $artistUser->artists()->attach($own->id);

        Livewire::actingAs($artistUser)->test(Index::class)
            ->set('type', 'releases')
            ->set('createMissing', true) // ignored for artist accounts
            ->set('file', $this->csv([
                ['release_title', 'release_artists', 'track_title'],
                ['Mine', 'Nova Lights', 'One'],
                ['Theirs', 'Secret Signing', 'Two'],
                ['New Person', 'Brand New Name', 'Three'],
            ]))
            ->call('check')
            ->assertSet('preview.count', 1)
            ->call('import');

        $this->assertTrue(Release::query()->withoutGlobalScopes()->where('title', 'Mine')->exists());
        $this->assertFalse(Release::query()->withoutGlobalScopes()->where('title', 'Theirs')->exists());
        $this->assertFalse(Artist::query()->withoutGlobalScopes()->where('name', 'Brand New Name')->exists());
    }

    public function test_templates_download_and_bad_files_are_rejected(): void
    {
        $this->actingAs($this->admin)->get('/bulk-upload/template/releases/csv')
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('type', 'artists')
            ->set('file', $this->csv([['Colour', 'Size'], ['red', 'L']]))
            ->call('check')
            ->assertHasErrors('file');
    }

    public function test_sales_report_import_page(): void
    {
        $this->actingAs($this->admin)->get('/reports/import')->assertOk()->assertSee('Import sales reports');
        $this->actingAs($this->makeUser(SystemRole::Viewer))->get('/reports/import')->assertForbidden();

        Livewire::actingAs($this->admin)->test(SalesImports::class)
            ->call('startCreating')
            ->set('name', '2026 October')->set('starts_on', '2026-10-01')->set('ends_on', '2026-10-31')
            ->call('createPeriod')
            ->assertHasNoErrors();

        $period = RoyaltyPeriod::query()->where('name', '2026 October')->sole();
        $this->actingAs($this->admin)->get('/reports/import?period='.$period->id)->assertOk()->assertSee('Upload, map columns and process');
        $this->actingAs($this->admin)->get('/reports')->assertSee(route('reports.import'), false);
    }
}

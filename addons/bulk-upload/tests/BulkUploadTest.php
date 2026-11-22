<?php

namespace LabelDesk\Addons\BulkUpload\Tests;

use App\Enums\SystemRole;
use App\Models\Artist;
use App\Models\Plugin;
use App\Models\Release;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Services\SettingsService;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LabelDesk\Addons\BulkUpload\Livewire\Index;
use LabelDesk\Addons\BulkUpload\Livewire\Settings;
use LabelDesk\Addons\BulkUpload\Models\BulkUpload;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

class BulkUploadTest extends TestCase
{
    use InstallsAddons, RefreshDatabase;

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

    protected function install(bool $activate = true): Plugin
    {
        return $this->installAddon(base_path('addons/bulk-upload'), $activate);
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
        $this->install();
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
        $this->install();
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
        $this->install();
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
        $this->install();
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
        $this->install();
        $this->actingAs($this->admin)->get('/bulk-upload/template/releases/csv')
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('type', 'artists')
            ->set('file', $this->csv([['Colour', 'Size'], ['red', 'L']]))
            ->call('check')
            ->assertHasErrors('file');
    }

    public function test_bulk_upload_runs_only_while_the_add_on_is_active(): void
    {
        $this->actingAs($this->admin)->get('/bulk-upload')->assertNotFound();
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertDontSee('/bulk-upload', false);

        $plugin = $this->install(activate: false);
        $this->actingAs($this->admin)->get('/bulk-upload')->assertNotFound();

        app(PluginManager::class)->activate($plugin, 'MD-LD-TEST-ADDN-AAAA-BBBB');
        $this->actingAs($this->admin)->get('/bulk-upload')->assertOk()->assertSee('Download the template');
        $this->actingAs($this->admin)->get('/dashboard')->assertSee(route('bulk-upload.index'), false);

        app(PluginManager::class)->deactivate($plugin->fresh());
        $this->actingAs($this->admin)->get('/bulk-upload')->assertNotFound();
        $this->actingAs($this->admin)->get('/bulk-upload/template/artists/csv')->assertNotFound();
        $this->actingAs($this->admin)->get('/settings/bulk-upload')->assertNotFound();
    }

    public function test_access_switches_live_in_their_own_settings_tab(): void
    {
        $this->install();

        $this->actingAs($this->admin)->get('/settings/catalog')->assertOk()
            ->assertDontSee('bulk_staff')->assertSee(route('bulk-upload.settings'), false);
        $this->actingAs($this->admin)->get('/settings/bulk-upload')->assertOk()->assertSee('Who may bulk upload');
        $this->actingAs($this->makeUser(SystemRole::LabelManager))->get('/settings/bulk-upload')->assertForbidden();

        Livewire::actingAs($this->admin)->test(Settings::class)
            ->assertSet('bulk_staff', true)->assertSet('bulk_artists', false)
            ->set('bulk_staff', false)->set('bulk_artists', true)
            ->call('save')->assertHasNoErrors();

        $settings = app(SettingsService::class);
        $this->assertFalse((bool) $settings->get('bulk_upload.staff'));
        $this->assertTrue((bool) $settings->get('bulk_upload.artists'));
    }

    public function test_first_activation_grants_the_permission_to_default_roles_once(): void
    {
        $plugin = $this->install();

        $manager = Role::findByName(SystemRole::LabelManager->value, 'web');
        $this->assertTrue($manager->hasPermissionTo('catalog.bulk_upload'));
        $this->assertTrue(Role::findByName(SystemRole::ArtistManager->value, 'web')->hasPermissionTo('catalog.bulk_upload'));
        $this->assertFalse(Role::findByName(SystemRole::Viewer->value, 'web')->hasPermissionTo('catalog.bulk_upload'));

        // An administrator's choice survives deactivating and activating again.
        $manager->revokePermissionTo('catalog.bulk_upload');
        app(PluginManager::class)->deactivate($plugin);
        app(PluginManager::class)->activate($plugin->fresh());
        $this->assertFalse($manager->fresh()->hasPermissionTo('catalog.bulk_upload'));
    }

    public function test_history_from_core_bulk_upload_is_kept(): void
    {
        // Installations upgraded from core bulk upload already have the table.
        Schema::create('bulk_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('type', 20);
            $table->string('original_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });
        DB::table('bulk_uploads')->insert(['user_id' => $this->admin->id, 'type' => 'artists', 'original_name' => 'old.csv', 'created_count' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $this->install();

        $this->assertSame('old.csv', BulkUpload::query()->sole()->original_name);
        $this->actingAs($this->admin)->get('/bulk-upload')->assertOk()->assertSee('old.csv');
    }
}

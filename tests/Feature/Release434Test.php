<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Track;
use App\Models\User;
use App\Plugins\PluginRegistry;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

/**
 * 4.3.4: media storage left LabelDesk core for the Media storage add-on. Core keeps
 * two extension points for it: background tasks and a source for masters that are
 * no longer on the server.
 */
class Release434Test extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
    }

    public function test_media_storage_is_not_part_of_core_any_more(): void
    {
        $this->assertFalse(Route::has('settings.media'));
        $this->actingAs($this->admin)->get('/settings/media-storage')->assertNotFound();
        $this->actingAs($this->admin)->get(route('settings.index'))->assertOk()->assertDontSee('Media storage');
        $this->assertFalse(class_exists('App\\Services\\MediaStorage\\MediaSyncService'));

        // New installs no longer get the media tables from core (the add-on creates them).
        $this->assertFalse(Schema::hasTable('media_storage_targets'));
        $this->assertTrue(Schema::hasColumn('releases', 'artwork_original_path'), 'core keeps the original artwork');
    }

    public function test_a_master_missing_from_the_server_is_fetched_by_an_add_on_or_explained(): void
    {
        $track = $this->trackOf($this->completeRelease());
        Storage::disk('local')->delete($track->audio_path);

        $this->actingAs($this->admin)->get(route('tracks.audio', $track))->assertNotFound()
            ->assertSee('the Media storage add-on must be active to fetch it');

        app(PluginRegistry::class)->fileSource(fn (Track $t, string $disposition, string $filename) => $t->is($track)
            ? response('from the cloud', 200, ['Content-Type' => 'audio/wav', 'X-Name' => $filename])
            : null, 'test');

        $this->actingAs($this->admin)->get(route('tracks.audio', $track))->assertOk()
            ->assertSee('from the cloud')->assertHeader('X-Name', $track->artistLine().' - Track 1.wav');
    }
}

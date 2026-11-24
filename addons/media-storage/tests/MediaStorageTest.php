<?php

namespace LabelDesk\Addons\MediaStorage\Tests;

use App\Enums\SystemRole;
use App\Models\Artist;
use App\Models\Plugin;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginRegistry;
use App\Services\SettingsService;
use App\Services\System\AutomaticTasks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LabelDesk\Addons\MediaStorage\Drivers\Dropbox;
use LabelDesk\Addons\MediaStorage\Livewire\Settings;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageFile;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageFolder;
use LabelDesk\Addons\MediaStorage\Models\MediaStorageTarget;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageManager;
use LabelDesk\Addons\MediaStorage\Services\MediaSyncService;
use Livewire\Livewire;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    protected User $admin;

    protected Release $release;

    protected Track $track;

    protected string $audio = "RIFF\x24\x00\x00\x00WAVEfmt master-bytes-0123456789";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        // The add-on requires LabelDesk 4.3.4 (media storage left core in that release).
        if (version_compare((string) config('labeldesk.product.version'), '4.3.4', '<')) {
            config(['labeldesk.product.version' => '4.3.4']);
        }
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();

        $artist = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->release = Release::factory()->create(['title' => 'Glass City: Live?', 'upc' => '036000291452']);
        $this->release->artists()->attach($artist->id, ['role' => 'primary', 'position' => 1]);
        Storage::disk('local')->put('releases/'.$this->release->id.'/audio/master.wav', $this->audio);
        $this->track = $this->release->tracks()->create(['title' => 'Glass City', 'isrc' => 'GB-AYE-26-00001', 'position' => 1]);
        $this->track->forceFill(['audio_path' => 'releases/'.$this->release->id.'/audio/master.wav', 'audio_mime' => 'audio/wav'])->save();

        if ($this->name() !== 'test_settings_page_and_tasks_exist_only_while_the_add_on_is_active') {
            $this->install();
        }
    }

    protected function install(bool $activate = true): Plugin
    {
        return $this->installAddon(base_path('addons/media-storage'), $activate);
    }

    protected function addTarget(string $driver = 'google_drive', array $attributes = [], array $credentials = []): MediaStorageTarget
    {
        $target = new MediaStorageTarget($attributes + [
            'driver' => $driver,
            'name' => 'Storage '.(MediaStorageTarget::query()->count() + 1),
            'enabled' => true,
            'account' => 'label@example.com',
            'folder_id' => 'ROOTFOLDER',
            'folder_name' => 'My Drive / LabelDesk',
            'connected_at' => now(),
        ]);
        $target->setCredentials($credentials + ['client_id' => 'client-123.apps.example', 'client_secret' => 'secret', 'refresh_token' => 'refresh-abc']);
        $target->save();

        return $target;
    }

    protected function mediaOptions(array $values): void
    {
        app(SettingsService::class)->setMany($values);
    }

    protected function fakeGoogle(?string $md5 = null, bool $remoteMissing = false, bool $folderExists = false): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/FILE1?alt=media*' => Http::response($this->audio, 200, ['Content-Length' => (string) strlen($this->audio)]),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3600]),
            'www.googleapis.com/drive/v3/files?*' => $folderExists
                ? Http::response(['files' => [['id' => 'FOLDER1']]])
                : Http::sequence()->push(['files' => []])->push(['id' => 'FOLDER1'])->whenEmpty(Http::response(['files' => []])),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'FOLDER1']),
            'www.googleapis.com/upload/drive/v3/files?*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session/xyz']),
            'www.googleapis.com/upload/session/xyz' => Http::response([
                'id' => 'FILE1', 'name' => 'GBAYE2600001.wav', 'size' => (string) strlen($this->audio), 'md5Checksum' => $md5 ?? md5($this->audio),
            ]),
            'www.googleapis.com/drive/v3/files/*' => $remoteMissing
                ? Http::response(['error' => ['message' => 'File not found']], 404)
                : Http::response(['id' => 'FILE1', 'name' => 'GBAYE2600001.wav', 'size' => (string) strlen($this->audio), 'trashed' => false]),
        ]);
    }

    // ------------------------------------------------------------ add-on lifecycle

    public function test_settings_page_and_tasks_exist_only_while_the_add_on_is_active(): void
    {
        $this->actingAs($this->admin)->get('/settings/media-storage')->assertNotFound();
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertDontSee('/settings/media-storage', false);
        $this->assertArrayNotHasKey('media', AutomaticTasks::all());

        $plugin = $this->install(activate: false);
        $this->actingAs($this->admin)->get('/settings/media-storage')->assertNotFound();

        app(PluginManager::class)->activate($plugin, 'MD-LD-TEST-ADDN-AAAA-BBBB');
        $this->assertTrue(Schema::hasTable('media_storage_targets') && Schema::hasTable('media_storage_folders') && Schema::hasTable('media_storage_files'));
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee(route('settings.media'), false);
        $this->actingAs($this->admin)->get('/settings/media-storage')->assertOk()->assertSee('Storage destinations');
        $this->actingAs($this->makeUser(SystemRole::LabelManager))->get('/settings/media-storage')->assertForbidden();
        $this->assertSame(5, AutomaticTasks::all()['media']);
        $this->assertContains('media-storage', array_column(app(PluginRegistry::class)->fileSources, 'plugin'));

        // The OAuth redirect URI customers registered with Google / Microsoft / Dropbox / Zoho is unchanged.
        $this->assertSame(url('settings/media-storage/callback/google_drive'), route('settings.media.callback', 'google_drive'));

        $target = $this->addTarget();
        app(PluginManager::class)->deactivate($plugin->fresh());
        $this->actingAs($this->admin)->get('/settings/media-storage')->assertNotFound();
        $this->actingAs($this->admin)->get('/settings/media-storage/callback/google_drive')->assertNotFound();

        // Deleting the add-on keeps destinations and upload records.
        app(PluginManager::class)->delete($plugin->fresh());
        $this->assertTrue(Schema::hasTable('media_storage_files'));
        $this->assertModelExists($target);
    }

    public function test_existing_core_tables_and_data_are_kept_when_installed(): void
    {
        // The add-on is installed in setUp; installing a newer copy must not touch existing rows.
        $target = $this->addTarget(attributes: ['name' => 'Kept drive']);
        (include base_path('addons/media-storage/database/migrations/2026_12_05_000000_media_storage_addon_tables.php'))->up();

        $this->assertSame('Kept drive', $target->fresh()->name);
        $this->assertSame('secret', $target->fresh()->credential('client_secret'));
    }

    // ------------------------------------------------------------ queueing hooks

    public function test_uploads_are_queued_when_tracks_and_releases_change(): void
    {
        $target = $this->addTarget();

        // New track with audio.
        Storage::disk('local')->put('releases/'.$this->release->id.'/audio/two.flac', 'flac-bytes');
        $two = $this->release->tracks()->create(['title' => 'Two', 'isrc' => 'GBAYE2600002', 'position' => 2]);
        $two->forceFill(['audio_path' => 'releases/'.$this->release->id.'/audio/two.flac', 'audio_mime' => 'audio/flac'])->save();
        $this->assertTrue(MediaStorageFile::query()->where(['target_id' => $target->id, 'kind' => 'audio', 'track_id' => $two->id, 'status' => 'pending'])->exists());

        // New artwork.
        $this->release->forceFill(['artwork_original_path' => 'releases/'.$this->release->id.'/artwork-original.png'])->save();
        $this->assertTrue(MediaStorageFile::query()->where(['target_id' => $target->id, 'kind' => 'artwork', 'release_id' => $this->release->id])->exists());

        // A renamed release re-queues every track (folder rename).
        MediaStorageFile::query()->update(['status' => 'uploaded']);
        $this->release->update(['title' => 'Renamed']);
        $this->assertSame(3, MediaStorageFile::query()->where('status', 'pending')->count(), 'artwork + both tracks');

        // A disabled storage gets nothing.
        $target->update(['enabled' => false]);
        MediaStorageFile::query()->delete();
        $this->track->forceFill(['isrc' => 'GBAYE2600009'])->save();
        $this->assertSame(0, MediaStorageFile::query()->count());
    }

    public function test_the_background_task_uploads_through_automatic_tasks(): void
    {
        $this->addTarget();
        $this->fakeGoogle();
        app(MediaSyncService::class)->queueTrack($this->track);

        // Only the add-on's task is due: core tasks count as just run.
        foreach (array_keys(AutomaticTasks::TASKS) as $task) {
            Cache::forever('labeldesk:auto:'.$task, now()->timestamp);
        }
        $ran = app(AutomaticTasks::class)->runDue(force: true);

        $this->assertSame(['media'], $ran);

        $this->assertSame('uploaded', MediaStorageFile::query()->sole()->status);
    }

    public function test_sync_command(): void
    {
        $this->addTarget();
        $this->fakeGoogle();
        // The add-on was activated after this process built its console application
        // (a real "php artisan" run boots active add-ons first): rebuild it.
        $this->app->make(ConsoleKernel::class)->setArtisan(null);

        $this->artisan('labeldesk:media:sync', ['--all' => true, '--budget' => 60])
            ->expectsOutputToContain('Queued 1 files.')
            ->expectsOutputToContain('uploaded 1')
            ->assertSuccessful();
    }

    // ------------------------------------------------------------ uploads (ported from core)

    public function test_folder_and_file_names_follow_release_title_upc_and_isrc(): void
    {
        $this->assertSame('Glass City- Live-_036000291452', MediaSyncService::folderName($this->release));
        $this->assertSame('GBAYE2600001.wav', MediaSyncService::audioName($this->track));

        $this->release->forceFill(['artwork_original_path' => 'releases/1/artwork-x-original.png', 'artwork_path' => 'releases/1/artwork-x.jpg'])->save();
        $this->assertSame('036000291452.png', MediaSyncService::artworkName($this->release));
    }

    public function test_audio_is_uploaded_unchanged_into_the_release_folder(): void
    {
        $target = $this->addTarget();
        $this->fakeGoogle();

        app(MediaSyncService::class)->queueTrack($this->track);
        $stats = app(MediaSyncService::class)->run(60);

        $this->assertSame(1, $stats['uploaded']);
        $record = MediaStorageFile::query()->where('kind', 'audio')->sole();
        $this->assertSame($target->id, $record->target_id);
        $this->assertSame('uploaded', $record->status);
        $this->assertSame('FILE1', $record->remote_id);
        $this->assertSame('GBAYE2600001.wav', $record->remote_name);
        $this->assertSame('FOLDER1', $record->remote_folder_id);
        $this->assertNull($record->delete_local_at, 'kept on the server by default');
        $this->assertSame('Glass City- Live-_036000291452', MediaStorageFolder::query()->sole()->name);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.googleapis.com/upload/session/xyz'
            && $r->body() === $this->audio
            && $r->header('Content-Range')[0] === 'bytes 0-'.(strlen($this->audio) - 1).'/'.strlen($this->audio));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadType=resumable') && $r['name'] === 'GBAYE2600001.wav' && $r['parents'] === ['FOLDER1']);
        Storage::disk('local')->assertExists($this->track->audio_path);
    }

    public function test_files_go_to_every_enabled_storage_including_several_google_drives(): void
    {
        $first = $this->addTarget(attributes: ['name' => 'Drive A', 'folder_id' => 'ROOT_A']);
        $second = $this->addTarget(attributes: ['name' => 'Drive B', 'folder_id' => 'ROOT_B'], credentials: ['refresh_token' => 'refresh-b']);
        $disabled = $this->addTarget(attributes: ['name' => 'Drive C', 'enabled' => false]);
        $this->fakeGoogle(folderExists: true);

        app(MediaSyncService::class)->queueTrack($this->track);
        $this->assertSame(2, MediaStorageFile::query()->count(), 'one record per enabled storage');

        $stats = app(MediaSyncService::class)->run(60);
        $this->assertSame(2, $stats['uploaded']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], MediaStorageFile::query()->where('status', 'uploaded')->pluck('target_id')->all());
        $this->assertSame(0, MediaStorageFile::query()->where('target_id', $disabled->id)->count());
        $this->assertEqualsCanonicalizing(['ROOT_A', 'ROOT_B'], MediaStorageFolder::query()->pluck('remote_parent_id')->all());

        // Each account uses its own refresh token.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['refresh_token'] === 'refresh-abc');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['refresh_token'] === 'refresh-b');
    }

    public function test_a_checksum_mismatch_is_never_trusted(): void
    {
        $this->addTarget();
        $this->fakeGoogle(md5: str_repeat('0', 32));

        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        $record = MediaStorageFile::query()->sole();
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('does not match', $record->last_error);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['trashed'] === true);
    }

    public function test_files_wait_for_missing_codes(): void
    {
        $this->addTarget();
        $this->fakeGoogle();
        $this->track->forceFill(['isrc' => null])->save();

        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        $record = MediaStorageFile::query()->sole();
        $this->assertSame('waiting', $record->status);
        $this->assertStringContainsString('ISRC', $record->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'upload'));
    }

    public function test_local_copy_is_removed_after_the_delay_and_streams_from_the_drive(): void
    {
        $target = $this->addTarget();
        $this->mediaOptions(['media.delete_local' => true, 'media.delete_after_days' => 0]);
        $this->fakeGoogle();

        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);
        $record = MediaStorageFile::query()->sole();
        $this->assertEqualsWithDelta(now()->addHours(12)->timestamp, $record->delete_local_at->timestamp, 5, '0 days = 12 hours');

        $this->travel(11)->hours();
        app(MediaSyncService::class)->pruneLocal();
        Storage::disk('local')->assertExists($this->track->audio_path);

        $this->travel(2)->hours();
        $this->assertSame(1, app(MediaSyncService::class)->pruneLocal());
        Storage::disk('local')->assertMissing($this->track->audio_path);
        $this->assertNotNull($record->fresh()->local_deleted_at);

        // Playback and download still work, streamed from Google Drive through core's file-source hook.
        $response = $this->actingAs($this->admin)->get(route('tracks.download', $this->track));
        $response->assertOk();
        $this->assertSame($this->audio, $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $player = $this->actingAs($this->admin)->get(route('tracks.audio', $this->track));
        $player->assertOk();
        $this->assertSame($this->audio, $player->streamedContent());

        // Removing the storage is refused while files exist only there.
        Livewire::actingAs($this->admin)->test(Settings::class)->call('delete', $target->id)->assertDispatched('toast', type: 'danger');
        $this->assertModelExists($target);
    }

    public function test_a_file_without_a_cloud_copy_is_not_found(): void
    {
        Storage::disk('local')->delete($this->track->audio_path);

        $this->actingAs($this->admin)->get(route('tracks.download', $this->track))->assertNotFound();
    }

    public function test_server_copy_is_kept_until_every_enabled_storage_has_it(): void
    {
        $this->addTarget(attributes: ['name' => 'Drive A']);
        $this->mediaOptions(['media.delete_local' => true, 'media.delete_after_days' => 0]);
        $this->fakeGoogle();

        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        // A second storage is added and enabled before the delay ends.
        $this->addTarget('dropbox', ['name' => 'Dropbox', 'folder_id' => '/LabelDesk']);
        app(MediaSyncService::class)->queueTrack($this->track);

        $this->travel(13)->hours();
        $this->assertSame(0, app(MediaSyncService::class)->pruneLocal());
        Storage::disk('local')->assertExists($this->track->audio_path);
    }

    public function test_local_copy_is_kept_when_the_drive_copy_is_missing(): void
    {
        $this->addTarget();
        $this->mediaOptions(['media.delete_local' => true, 'media.delete_after_days' => 1]);
        $this->fakeGoogle(remoteMissing: true);
        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        $this->travel(2)->days();

        $this->assertSame(0, app(MediaSyncService::class)->pruneLocal());
        Storage::disk('local')->assertExists($this->track->audio_path);
        $this->assertSame('pending', MediaStorageFile::query()->sole()->status);
    }

    public function test_renaming_a_release_renames_the_remote_folder(): void
    {
        $this->addTarget();
        $this->fakeGoogle();
        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        $this->release->update(['title' => 'Glass City Deluxe']);
        app(MediaSyncService::class)->run(60);

        $this->assertSame('Glass City Deluxe_036000291452', MediaStorageFolder::query()->sole()->name);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/files/FOLDER1') && $r['name'] === 'Glass City Deluxe_036000291452');
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'upload/session'))->count(), 'not uploaded twice');
    }

    public function test_onedrive_upload_session(): void
    {
        $target = $this->addTarget('onedrive', credentials: ['tenant' => 'common']);
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(['access_token' => 'eyJ0', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me/drive/items/ROOTFOLDER:/*' => Http::response(['error' => ['code' => 'itemNotFound']], 404),
            'graph.microsoft.com/v1.0/me/drive/items/ROOTFOLDER/children' => Http::response(['id' => 'ODFOLDER']),
            'graph.microsoft.com/v1.0/me/drive/items/ODFOLDER:/*' => Http::response(['uploadUrl' => 'https://upload.onedrive.example/session/1']),
            'upload.onedrive.example/*' => Http::response(['id' => 'ODFILE', 'name' => 'GBAYE2600001.wav', 'size' => strlen($this->audio), 'file' => ['hashes' => ['sha1Hash' => strtoupper(sha1($this->audio))]]], 201),
        ]);

        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        $record = MediaStorageFile::query()->sole();
        $this->assertSame('uploaded', $record->status, (string) $record->last_error);
        $this->assertSame('ODFILE', $record->remote_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'GBAYE2600001.wav:/createUploadSession'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.onedrive.example/session/1' && ! $r->hasHeader('Authorization') && $r->body() === $this->audio);
        $this->assertSame('rotated-refresh', $target->fresh()->credential('refresh_token'), 'rotated refresh token kept');
    }

    public function test_onedrive_for_business_uses_the_organisation_tenant(): void
    {
        $target = $this->addTarget('onedrive_business', credentials: ['tenant' => 'contoso.onmicrosoft.com']);
        $url = app(MediaStorageManager::class)->driver($target)->authorizeUrl('https://labeldesk.test/cb', 'state');

        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?', $url);
        $this->assertSame('OneDrive for Business', app(MediaStorageManager::class)->driver($target)::label());
    }

    public function test_dropbox_upload_session_is_verified_with_the_content_hash(): void
    {
        $this->addTarget('dropbox', ['folder_id' => '/LabelDesk', 'folder_name' => 'Dropbox / LabelDesk']);
        $folder = '/LabelDesk/Glass City- Live-_036000291452';
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'sl.token', 'expires_in' => 14400]),
            'api.dropboxapi.com/2/files/get_metadata' => Http::response(['error_summary' => 'path/not_found/'], 409),
            'api.dropboxapi.com/2/files/create_folder_v2' => Http::response(['metadata' => ['path_display' => $folder]]),
            'content.dropboxapi.com/2/files/upload_session/start' => Http::response(['session_id' => 'SESSION1']),
            'content.dropboxapi.com/2/files/upload_session/finish' => Http::response([
                'id' => 'id:abc', 'name' => 'GBAYE2600001.wav', 'size' => strlen($this->audio), 'content_hash' => Dropbox::contentHash(Storage::disk('local')->path($this->track->audio_path)),
            ]),
        ]);

        app(MediaSyncService::class)->queueTrack($this->track);
        app(MediaSyncService::class)->run(60);

        $record = MediaStorageFile::query()->sole();
        $this->assertSame('uploaded', $record->status, (string) $record->last_error);
        $this->assertSame('id:abc', $record->remote_id);
        $this->assertSame($folder, $record->remote_folder_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/upload_session/start') && $r->body() === $this->audio);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/upload_session/finish')
            && json_decode($r->header('Dropbox-API-Arg')[0], true)['commit']['path'] === $folder.'/GBAYE2600001.wav');
    }

    public function test_dropbox_content_hash_matches_the_reference_algorithm(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dbx');
        file_put_contents($path, str_repeat('a', 4 * 1024 * 1024 + 10));
        $expected = hash('sha256', hash('sha256', str_repeat('a', 4 * 1024 * 1024), true).hash('sha256', str_repeat('a', 10), true));

        $this->assertSame($expected, Dropbox::contentHash($path));
        @unlink($path);
    }

    // ------------------------------------------------------------ settings page (ported from core)

    public function test_oauth_connect_and_callback_per_destination(): void
    {
        $target = $this->addTarget(attributes: ['enabled' => false, 'account' => null, 'folder_id' => null, 'folder_name' => null, 'connected_at' => null]);
        $target->credentials = ['client_id' => 'client-123.apps.example', 'client_secret' => 'secret'];
        $target->save();

        $location = (string) $this->actingAs($this->admin)->get(route('settings.media.connect', $target))->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString(rawurlencode(route('settings.media.callback', 'google_drive')), $location);

        // A forged state is refused.
        $this->actingAs($this->admin)->get(route('settings.media.callback', ['driver' => 'google_drive', 'state' => 'forged', 'code' => 'x']))
            ->assertRedirect()->assertSessionHas('error');

        parse_str((string) parse_url((string) $this->actingAs($this->admin)->get(route('settings.media.connect', $target))->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29', 'refresh_token' => '1//refresh', 'expires_in' => 3600]),
            'www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'label@example.com']]),
        ]);
        $this->actingAs($this->admin)->get(route('settings.media.callback', ['driver' => 'google_drive', 'state' => $query['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('settings.media'))->assertSessionHas('success');

        $target->refresh();
        $this->assertSame('1//refresh', $target->credential('refresh_token'));
        $this->assertSame('label@example.com', $target->account);
        $this->assertNotNull($target->connected_at);
        $this->assertStringNotContainsString('1//refresh', (string) $target->getRawOriginal('credentials'), 'stored encrypted');
    }

    public function test_settings_page_add_enable_and_disable(): void
    {
        $this->actingAs($this->makeUser(SystemRole::LabelManager))->get(route('settings.media'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.media'))->assertOk()
            ->assertSee('Add storage')->assertSee('Zoho WorkDrive')->assertSee('OneDrive for Business')->assertSee('Dropbox')->assertSee('FTP');

        // Adding a second Google Drive redirects to its own OAuth connect.
        $this->addTarget(attributes: ['name' => 'Google Drive']);
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('startAdd', 'google_drive')->assertSet('name', 'Google Drive 2')
            ->assertSee(route('settings.media.callback', 'google_drive'))
            ->set('creds.client_id', 'second-client')->set('creds.client_secret', 'second-secret')
            ->call('saveTarget')->assertHasNoErrors()
            ->assertRedirect(route('settings.media.connect', MediaStorageTarget::query()->latest('id')->first()));
        $new = MediaStorageTarget::query()->latest('id')->first();
        $this->assertFalse($new->enabled);
        $this->assertSame('second-secret', $new->credential('client_secret'));

        // Cannot enable before it is connected and has a folder.
        Livewire::actingAs($this->admin)->test(Settings::class)->call('toggle', $new->id)->assertDispatched('toast', type: 'danger');
        $this->assertFalse($new->fresh()->enabled);

        // Editing keeps the stored secret when the field is left blank.
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('edit', $new->id)->assertSet('creds.client_secret', '')->set('name', 'Archive drive')->call('saveTarget')->assertHasNoErrors();
        $this->assertSame('second-secret', $new->fresh()->credential('client_secret'));
        $this->assertSame('Archive drive', $new->fresh()->name);

        // A ready storage enables and queues existing files; disabling stops new uploads to it.
        $new->update(['folder_id' => 'ROOT_X', 'folder_name' => 'My Drive / X', 'account' => 'x@example.com', 'connected_at' => now()]);
        $new->setCredentials(['refresh_token' => 'r']);
        $new->save();
        Livewire::actingAs($this->admin)->test(Settings::class)->call('toggle', $new->id)->assertDispatched('toast', type: 'success');
        $this->assertTrue($new->fresh()->enabled);
        $this->assertSame(1, MediaStorageFile::query()->where('target_id', $new->id)->where('kind', 'audio')->count());

        Livewire::actingAs($this->admin)->test(Settings::class)->call('toggle', $new->id);
        $this->assertFalse($new->fresh()->enabled);
        $this->assertFalse(app(MediaStorageManager::class)->activeTargets()->contains('id', $new->id));

        // Shared options.
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('delete_local', true)->set('delete_after_days', 400)->call('saveOptions')->assertHasErrors('delete_after_days')
            ->set('delete_after_days', 3)->call('saveOptions')->assertHasNoErrors();
        $this->assertTrue((bool) app(SettingsService::class)->get('media.delete_local'));
    }

    public function test_ftp_details_are_tested_when_saved(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('startAdd', 'ftp')
            ->set('creds.host', 'bad host!')->call('saveTarget')->assertHasErrors('creds.host')
            ->set('creds.host', '127.0.0.1')->set('creds.port', '1')->set('creds.username', 'label')->set('creds.password', 'pw')
            ->call('saveTarget')->assertHasNoErrors()->assertDispatched('toast', type: 'danger');

        $target = MediaStorageTarget::query()->where('driver', 'ftp')->sole();
        $this->assertNull($target->connected_at);
        $this->assertFalse($target->enabled);
        $this->assertSame('pw', $target->credential('password'));
        $this->assertStringNotContainsString('"pw"', (string) $target->getRawOriginal('credentials'));
    }
}

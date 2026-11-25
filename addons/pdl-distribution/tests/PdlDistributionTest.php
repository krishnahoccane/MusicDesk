<?php

namespace LabelDesk\Addons\PdlDistribution\Tests;

use App\Enums\SystemRole;
use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Release;
use App\Models\Setting;
use App\Models\Track;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginRegistry;
use App\Services\SettingsService;
use App\Services\System\AutomaticTasks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LabelDesk\Addons\PdlDistribution\Livewire\ReleasePage;
use LabelDesk\Addons\PdlDistribution\Livewire\Settings;
use LabelDesk\Addons\PdlDistribution\Models\PdlDelivery;
use LabelDesk\Addons\PdlDistribution\Services\CosmosClient;
use LabelDesk\Addons\PdlDistribution\Services\DeliveryException;
use LabelDesk\Addons\PdlDistribution\Services\DeliveryService;
use LabelDesk\Addons\PdlDistribution\Services\PayloadBuilder;
use LabelDesk\Addons\PdlDistribution\Services\PdlSettings;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

class PdlDistributionTest extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    protected const API = 'https://pdl.test/v2.0';

    protected const ALBUM_ID = '66d6c6a6579fb93b6f2c68d0';

    protected const LABEL_ID = '61b990392d56b657a5c186d7';

    protected const PASSWORD = 'pdl-test-password-not-real';

    protected User $admin;

    protected Release $release;

    protected Track $one;

    protected Track $two;

    protected string $audioOne = "RIFF\x24\x00\x00\x00WAVEfmt first-master-bytes-0123456789";

    protected string $audioTwo = "RIFF\x24\x00\x00\x00WAVEfmt second-master-bytes-abcdefghij";

    /** @var array<string, mixed> current fake answers by path */
    protected array $responses = [];

    protected bool $faked = false;

    /** @var array<string, array{0: string, 1: string, 2: ?string, 3: bool}> S3 uploads: file => [method, md5 of body, Content-Length, has Authorization] */
    protected array $uploads = [];

    /** @var array<string, int> request counts by path */
    protected array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin, ['name' => 'Asha Admin']);
        $this->activateLicense();
        $this->makeRelease();

        if ($this->name() !== 'test_add_on_lifecycle_pages_permissions_and_task') {
            $this->installAddon(base_path('addons/pdl-distribution'));
        }
    }

    // ------------------------------------------------------------ fixtures

    protected function makeRelease(): void
    {
        $pop = Genre::query()->firstOrCreate(['name' => 'Pop']);
        $hindi = Language::query()->firstOrCreate(['code' => 'hi'], ['name' => 'Hindi']);
        $zxx = Language::query()->firstOrCreate(['code' => 'zxx'], ['name' => 'Instrumental (no lyrics)']);

        $main = Artist::factory()->create(['name' => 'Nova Lights']);
        $main->links()->create(['platform' => 'spotify', 'url' => 'https://open.spotify.com/artist/1dVygo6tRFXC8CSWURQJq2?si=abc']);
        $main->links()->create(['platform' => 'apple_music', 'url' => 'https://music.apple.com/in/artist/nova-lights/57731230']);
        $main->links()->create(['platform' => 'instagram', 'url' => 'https://www.instagram.com/novalights']);
        $main->links()->create(['platform' => 'facebook', 'url' => 'https://www.facebook.com/novalights']);
        $feat = Artist::factory()->create(['name' => 'Kiran Rao']);
        $remixer = Artist::factory()->create(['name' => 'DJ Sitar']);

        $this->release = Release::factory()->create([
            'title' => 'Glass City', 'version' => null, 'type' => 'ep', 'upc' => '1941570109481', 'catalog_number' => 'TST001',
            'label_name' => 'Test Records', 'c_line' => '2026 Test Records', 'p_line' => '2026 Test Records',
            'release_date' => '2026-12-24', 'original_release_date' => '2026-12-01', 'genre_id' => $pop->id, 'language_id' => $hindi->id, 'explicit' => false,
        ]);
        $this->release->artists()->attach($main->id, ['role' => 'primary', 'position' => 1]);
        $this->release->artists()->attach($feat->id, ['role' => 'featuring', 'position' => 2]);
        $this->release->forceFill(['status' => 'ready'])->save();

        $artwork = imagecreatetruecolor(1500, 1500);
        imagefill($artwork, 0, 0, imagecolorallocate($artwork, 200, 40, 90));
        ob_start();
        imagejpeg($artwork, null, 80);
        Storage::disk('local')->put('releases/'.$this->release->id.'/artwork-1.jpg', (string) ob_get_clean());
        $this->release->forceFill(['artwork_path' => 'releases/'.$this->release->id.'/artwork-1.jpg'])->save();

        $composer = Contributor::query()->create(['name' => 'Ravi Shankar Iyer', 'ipi_number' => '00123456789', 'pro' => 'IPRS']);
        $lyricist = Contributor::query()->create(['name' => 'Meera Joshi', 'pro' => 'PRS']);
        $writer = Contributor::query()->create(['name' => 'Sam Writer']);
        $producer = Contributor::query()->create(['name' => 'Pro Ducer']);
        $publisher = Contributor::query()->create(['name' => 'Glass Publishing']);

        $this->one = $this->release->tracks()->create(['title' => 'Glass City', 'isrc' => 'IN-A1B-26-00001', 'position' => 1, 'duration_seconds' => 205, 'explicit' => true]);
        $this->one->artists()->attach($main->id, ['role' => 'primary', 'position' => 1]);
        $this->one->artists()->attach($feat->id, ['role' => 'featuring', 'position' => 2]);
        $this->one->credits()->create(['contributor_id' => $composer->id, 'role' => 'composer']);
        $this->one->credits()->create(['contributor_id' => $lyricist->id, 'role' => 'lyricist']);
        $this->one->credits()->create(['contributor_id' => $producer->id, 'role' => 'producer']);
        $this->one->credits()->create(['contributor_id' => $publisher->id, 'role' => 'publisher']);

        $this->two = $this->release->tracks()->create(['title' => 'Glass City (Night Mix)', 'isrc' => 'INA1B2600002', 'position' => 2, 'duration_seconds' => 3725, 'language_id' => $zxx->id]);
        $this->two->artists()->attach($main->id, ['role' => 'primary', 'position' => 1]);
        $this->two->artists()->attach($remixer->id, ['role' => 'remixer', 'position' => 2]);
        $this->two->credits()->create(['contributor_id' => $writer->id, 'role' => 'songwriter']);

        foreach ([[$this->one, $this->audioOne], [$this->two, $this->audioTwo]] as $i => [$track, $bytes]) {
            $path = 'releases/'.$this->release->id.'/audio/master-'.($i + 1).'.wav';
            Storage::disk('local')->put($path, $bytes);
            $track->forceFill(['audio_path' => $path, 'audio_original_name' => 'Master '.($i + 1).'.wav', 'audio_mime' => 'audio/wav',
                'audio_size' => strlen($bytes), 'audio_sample_rate' => 44100, 'audio_bit_depth' => 24])->save();
        }
    }

    protected function configure(array $overrides = []): void
    {
        $settings = app(PdlSettings::class);
        $settings->save($overrides + [
            'enabled' => true, 'base_url' => 'https://pdl.test', 'email' => 'label@example.com', 'label_id' => self::LABEL_ID,
            'label_name' => 'Test Records Pvt Ltd', 'genre_map' => [(string) $this->release->genre_id => 'Pop|Indian Pop'], 'default_mood' => 'Happy',
        ]);
        $settings->setPassword(self::PASSWORD);
    }

    /**
     * COSMOS and S3 fake. $responses overrides a path ("album/add/meta", "s3", …)
     * with a response or a closure (Request $r, int $n) → response.
     *
     * @param  array<string, mixed>  $responses
     */
    protected function fakePdl(array $responses = [], array $album = []): void
    {
        $this->calls = [];
        $album += ['id' => self::ALBUM_ID, 'name' => 'Glass City', 'submitted' => true, 'verified' => false, 'reportissue_flag' => false, 'releases' => [], 'upc' => '1941570109481',
            'artwork' => ['url' => 'https://s3.test/stored.jpg?X-Amz-Signature=x', 'size' => 1, 'md_5' => 'abc'],
            'songs' => [['id' => '6694fb0b579fb93bb54d9e8c', 'isrc' => 'INA1B2600001', 'title' => 'Old title', 'track_order' => 1, 'crbt_cuts' => [['name' => 'Glass City', 'crbt_cut_media' => ['url' => '']]],
                'media' => ['url' => 'https://s3.test/stored.wav?X-Amz-Signature=y', 'size' => 1, 'md_5' => 'def']],
                ['id' => '6694fb0b579fb93bb54d9e8d', 'isrc' => 'INA1B2600002', 'title' => 'Glass City (Night Mix)', 'track_order' => 2]]];
        $defaults = [
            'user/system/signin' => Http::response($this->signInAnswer('cosmos-bearer-123')),
            'album/add/meta' => Http::response(['success' => true, 'data' => ['err' => null, 'token' => 'ingest-token-1', 'signed_albums' => [[
                'name' => 'Glass City',
                'inlay' => ['filename' => '1941570109481.jpg', 'signed_url' => 'https://s3.test/inlay.jpg?X-Amz-Signature=secret1'],
                'songs' => [
                    ['isrc' => 'INA1B2600001', 'media' => ['filename' => 'INA1B2600001.wav', 'signed_url' => 'https://s3.test/one.wav?X-Amz-Signature=secret2']],
                    ['isrc' => 'INA1B2600002', 'media' => ['filename' => 'INA1B2600002.wav', 'signed_url' => 'https://s3.test/two.wav?X-Amz-Signature=secret3']],
                ],
                'warnings' => ['Singer is empty For : Glass City (Night Mix)'],
            ]]]]),
            's3' => fn (Request $r) => Http::response('', 200, ['ETag' => '"'.md5($r->body()).'"']),
            'album/verify/meta' => Http::response(['success' => true, 'data' => ['msg' => 'verified', 'album_id' => self::ALBUM_ID]]),
            'album/pdl/submit' => Http::response(['success' => true, 'data' => ['upc' => '1941570109481']]),
            'album/page' => Http::response(['success' => true, 'data' => ['albums' => [$album], 'total_count' => 1]]),
            'reportissue/getbyid' => Http::response(['success' => true, 'data' => ['report_albumissue_details' => [
                ['date_of_reporting' => '12/20/2026', 'issue_type' => 'UserComment', 'report_comment' => 'Artwork has a DSP logo.'],
            ]]]),
            'album/media/update' => Http::response(['success' => true, 'data' => []]),
            'album/media/signedurl' => Http::response(['success' => true, 'data' => ['url' => 'https://s3.test/replace.jpg?X-Amz-Signature=secret4']]),
        ];
        $this->responses = $responses + $defaults;
        if ($this->faked) {
            return; // Http::fake() stubs add up: one stub reads the current answers
        }
        $this->faked = true;

        Http::fake(function (Request $request) {
            $responses = $this->responses;
            $url = $request->url();
            $key = str_starts_with($url, 'https://s3.test/') ? 's3' : (string) preg_replace('#^'.preg_quote(self::API, '#').'/([^?]*).*$#', '$1', $url);
            $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
            if ($key === 's3') {
                $this->uploads[(string) parse_url($url, PHP_URL_PATH) === '' ? $url : ltrim((string) parse_url($url, PHP_URL_PATH), '/')] = [
                    $request->method(), md5($request->body()), $request->header('Content-Length')[0] ?? null, $request->hasHeader('Authorization'),
                ];
            }
            $response = $responses[$key] ?? Http::response(['success' => false, 'data' => ['code' => 0, 'msg' => 'unexpected '.$key]], 404);

            return $response instanceof \Closure ? $response($request, $this->calls[$key]) : $response;
        });
    }

    /** The answer of COSMOS staging's /user/system/signin (values made up). */
    protected function signInAnswer(string $token, string $expiry = '+10 days'): array
    {
        return ['success' => true, 'data' => ['data' => [
            'id' => '68f2247c579fb94fbf3a0000', 'email' => 'label@example.com', 'first_name' => 'Test', 'last_name' => 'Label',
            'roles' => ['Label'], 'status' => 'Active', 'blocked' => false,
            'oauth2_token' => ['access_token' => $token, 'token_type' => 'Bearer', 'expiry' => now()->modify($expiry)->utc()->format('Y-m-d\TH:i:s\Z')],
        ]]];
    }

    protected function send(array $options = []): PdlDelivery
    {
        return app(DeliveryService::class)->queue($this->release->fresh(), $this->admin, $options + [
            'platforms' => 'AllPlatformNotOnYouTube', 'allow_any_go_live_date' => true, 'mood' => 'Romantic',
        ]);
    }

    protected function delivery(): PdlDelivery
    {
        return PdlDelivery::query()->where('release_id', $this->release->id)->firstOrFail();
    }

    // ------------------------------------------------------------ lifecycle & permissions

    public function test_add_on_lifecycle_pages_permissions_and_task(): void
    {
        $this->actingAs($this->admin)->get('/distribution/pdl')->assertNotFound();
        $this->assertArrayNotHasKey('pdl', AutomaticTasks::all());

        $plugin = $this->installAddon(base_path('addons/pdl-distribution'));

        $this->assertTrue(Schema::hasTable('pdl_deliveries') && Schema::hasTable('pdl_delivery_events'));
        $this->assertSame(['pdl.view', 'pdl.deliver', 'pdl.manage'], app(PluginRegistry::class)->permissionNames('pdl-distribution'));
        $this->assertSame(3, Permission::query()->whereIn('name', ['pdl.view', 'pdl.deliver', 'pdl.manage'])->count());
        $this->assertSame(1, AutomaticTasks::all()['pdl']);

        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee(route('settings.pdl'), false);
        $this->actingAs($this->admin)->get('/settings/pdl-distribution')->assertOk()->assertSee('PDL COSMOS connection');
        $this->actingAs($this->admin)->get('/distribution/pdl')->assertOk()->assertSee('Glass City');
        $this->actingAs($this->admin)->get('/distribution/pdl/releases/'.$this->release->id)->assertOk()->assertSee('Send to PDL');

        // The migration only creates missing tables and keeps rows.
        PdlDelivery::query()->create(['release_id' => $this->release->id, 'status' => 'submitted', 'stage' => 'done', 'pdl_album_id' => self::ALBUM_ID]);
        (include base_path('addons/pdl-distribution/database/migrations/2026_12_10_000000_pdl_distribution_tables.php'))->up();
        $this->assertSame(1, PdlDelivery::query()->count());

        app(PluginManager::class)->deactivate($plugin->fresh());
        $this->actingAs($this->admin)->get('/distribution/pdl')->assertNotFound();
        $this->actingAs($this->admin)->get('/settings/pdl-distribution')->assertNotFound();

        app(PluginManager::class)->delete($plugin->fresh());
        $this->assertTrue(Schema::hasTable('pdl_deliveries'));
        $this->assertSame(1, PdlDelivery::query()->count(), 'uninstall keeps deliveries');
    }

    public function test_pages_and_actions_are_permission_gated(): void
    {
        $this->configure();
        $nobody = User::factory()->create()->fresh();
        $viewer = User::factory()->create()->fresh();
        $viewer->givePermissionTo('pdl.view');
        $deliverer = User::factory()->create()->fresh();
        $deliverer->givePermissionTo(['pdl.view', 'pdl.deliver']);

        $this->actingAs($nobody)->get('/distribution/pdl')->assertForbidden();
        $this->actingAs($viewer)->get('/distribution/pdl')->assertOk();
        $this->actingAs($viewer)->get('/distribution/pdl/releases/'.$this->release->id)->assertOk()->assertDontSee('wire:submit="send"', false);
        $this->actingAs($viewer)->get('/settings/pdl-distribution')->assertForbidden();
        $this->actingAs($deliverer)->get('/settings/pdl-distribution')->assertForbidden();

        Livewire::actingAs($viewer)->test(ReleasePage::class, ['release' => $this->release])->call('send')->assertForbidden();
        $this->assertSame(0, PdlDelivery::query()->count());

        $this->fakePdl();
        Livewire::actingAs($deliverer)->test(ReleasePage::class, ['release' => $this->release])
            ->set('mood', 'Happy')->call('send')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $this->assertSame('queued', $this->delivery()->status);
        $this->assertSame($deliverer->id, $this->delivery()->requested_by);
        $this->assertTrue(ActivityLog::query()->where('event', 'pdl.queued')->where('user_id', $deliverer->id)->exists());
    }

    // ------------------------------------------------------------ settings

    public function test_settings_are_validated_and_the_password_is_stored_encrypted(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('enabled', true)->set('base_url', 'not a url')->set('email', 'nope')->set('label_id', 'XYZ')
            ->set('platforms', 'Everywhere')->set('genre_map', [(string) $this->release->genre_id => 'Pop|Not a sub-genre'])
            ->set('language_map', ['hi' => 'klingon'])->set('crbt_start', 9999)
            ->call('save')
            ->assertHasErrors(['base_url', 'email', 'label_id', 'platforms', 'genre_map.'.$this->release->genre_id, 'language_map.hi', 'crbt_start']);
        $this->assertNull(app(SettingsService::class)->get('pdl.email'));

        // Enabling needs a password.
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('enabled', true)->set('base_url', 'https://pdl.test/')->set('email', 'label@example.com')->set('label_id', self::LABEL_ID)
            ->call('save')->assertHasErrors('password');

        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('enabled', true)->set('base_url', 'https://pdl.test/')->set('email', 'label@example.com')->set('label_id', strtoupper(self::LABEL_ID))
            ->set('password', self::PASSWORD)->set('genre_map', [(string) $this->release->genre_id => 'Pop|Indian Pop'])->set('language_map', ['hi' => 'hindi'])
            ->call('save')->assertHasNoErrors()->assertSet('password', '')->assertDontSee(self::PASSWORD);

        $settings = app(PdlSettings::class);
        $this->assertTrue($settings->isEnabled());
        $this->assertSame('https://pdl.test', $settings->baseUrl());
        $this->assertSame(self::LABEL_ID, $settings->get('label_id'));
        $this->assertSame(['Pop', 'Indian Pop'], $settings->genreFor($this->release->genre_id));

        $row = Setting::query()->where(['group' => 'pdl', 'key' => 'password'])->firstOrFail();
        $this->assertSame('encrypted', $row->type);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $row->value);
        $this->assertSame(self::PASSWORD, Crypt::decryptString($row->value));

        // Saving again with an empty password keeps it; the page never shows it.
        Livewire::actingAs($this->admin)->test(Settings::class)->assertSet('password', '')->call('save')->assertHasNoErrors()->assertDontSee(self::PASSWORD);
        $this->assertSame(self::PASSWORD, app(PdlSettings::class)->password());
        $this->actingAs($this->admin)->get('/settings/pdl-distribution')->assertOk()->assertDontSee(self::PASSWORD);

        $this->assertTrue(ActivityLog::query()->where('event', 'settings.updated')->exists());
        $this->assertFalse(ActivityLog::query()->where('properties', 'like', '%'.self::PASSWORD.'%')->orWhere('description', 'like', '%'.self::PASSWORD.'%')->exists());
    }

    public function test_test_connection_signs_in_for_real_and_reports_pdl_errors(): void
    {
        $this->fakePdl(['user/system/signin' => fn (Request $r, int $n) => $n === 1
            ? Http::response($this->signInAnswer('cosmos-bearer-123'))
            : Http::response(['success' => false, 'data' => ['code' => -1, 'msg' => 'Invalid email or password']], 200)]);

        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('base_url', 'https://pdl.test')->set('email', 'label@example.com')->set('password', self::PASSWORD)
            ->call('testConnection')->assertDispatched('toast', type: 'success', message: 'Signed in to PDL as label@example.com.')
            ->call('testConnection')->assertDispatched('toast', type: 'danger', message: 'PDL refused the sign-in: Invalid email or password');

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/user/system/signin' && $r['email'] === 'label@example.com' && $r['password'] === self::PASSWORD);
        $this->assertNull(app(PdlSettings::class)->password(), 'testing does not save');
    }

    // ------------------------------------------------------------ mapping

    public function test_payload_mapping_of_a_full_release_matches_the_snapshot(): void
    {
        $this->configure();
        $check = app(PayloadBuilder::class)->check($this->release, 'Romantic');
        $this->assertSame([], $check['problems']);

        $built = app(PayloadBuilder::class)->build($this->release, 'Romantic');
        $body = $built['body'];

        // Artwork bytes depend on the GD build: compare its size and md5 with the file, not the snapshot.
        $artwork = Storage::disk('local')->path($this->release->artwork_path);
        $this->assertSame(['size' => filesize($artwork), 'md5' => md5_file($artwork)], array_intersect_key($body['albums'][0]['inlay'], ['size' => 0, 'md5' => 0]));
        $body['albums'][0]['inlay']['size'] = 0;
        $body['albums'][0]['inlay']['md5'] = 'artwork-md5';

        $snapshot = __DIR__.'/fixtures/full-release-payload.json';
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (! is_file($snapshot) || getenv('UPDATE_SNAPSHOTS')) {
            @mkdir(dirname($snapshot), 0777, true);
            file_put_contents($snapshot, $json);
            $this->markTestIncomplete('Snapshot written; review it and run again.');
        }
        $this->assertSame(file_get_contents($snapshot), $json);

        $this->assertSame(['inlay', 'song:INA1B2600001', 'song:INA1B2600002'], array_column($built['files'], 'key'));
        $this->assertSame(md5($this->audioOne), $built['files'][1]['md5']);
        $this->assertSame(strlen($this->audioTwo), $built['files'][2]['size']);
    }

    public function test_artist_ids_are_read_from_profile_links(): void
    {
        $this->assertSame('1dVygo6tRFXC8CSWURQJq2', PayloadBuilder::spotifyId('https://open.spotify.com/intl-de/artist/1dVygo6tRFXC8CSWURQJq2'));
        $this->assertSame('', PayloadBuilder::spotifyId('https://open.spotify.com/album/1dVygo6tRFXC8CSWURQJq2'));
        $this->assertSame('213157068', PayloadBuilder::appleId('https://music.apple.com/us/artist/pritam/213157068'));
        $this->assertSame('213157068', PayloadBuilder::appleId('https://itunes.apple.com/artist/id213157068'));
    }

    public function test_releases_with_missing_or_unmapped_data_cannot_be_queued(): void
    {
        $this->configure(['genre_map' => []]);
        $this->one->credits()->where('role', 'lyricist')->delete();
        $this->one->forceFill(['isrc' => 'BAD'])->save();
        Storage::disk('local')->delete($this->two->audio_path);
        $this->release->forceFill(['status' => 'draft', 'release_date' => now()->addDays(60)->toDateString()])->save();

        try {
            $this->send();
            $this->fail('Expected a DeliveryException');
        } catch (DeliveryException $e) {
            $text = implode("\n", $e->problems);
            $this->assertStringContainsString('approved or ready', $text);
            $this->assertStringContainsString('Map the genre "Pop"', $text);
            $this->assertStringContainsString('needs a valid ISRC', $text);
            $this->assertStringContainsString('needs a lyricist', $text);
            $this->assertStringContainsString('is not on this server', $text);
            $this->assertStringContainsString('at most 45 days before its go-live date', $text);
        }
        $this->assertSame(0, PdlDelivery::query()->count());

        Livewire::actingAs($this->admin)->test(ReleasePage::class, ['release' => $this->release])
            ->assertSee('Before it can be sent')->assertSee('Map the genre');
    }

    // ------------------------------------------------------------ delivery flow

    public function test_the_whole_delivery_flow_sends_metadata_uploads_files_verifies_and_submits(): void
    {
        $this->configure();
        $this->fakePdl();
        $delivery = $this->send();
        $this->assertSame(['queued', 'queued'], [$delivery->stage, $delivery->status]);
        Http::assertNothingSent(); // nothing happens while the page is answered

        $stats = app(DeliveryService::class)->run();
        $this->assertSame(1, $stats['processed']);

        $delivery = $this->delivery();
        $this->assertSame('done', $delivery->stage);
        $this->assertSame('submitted', $delivery->status);
        $this->assertSame(self::ALBUM_ID, $delivery->pdl_album_id);
        $this->assertSame('1941570109481', $delivery->pdl_upc);
        $this->assertNotNull($delivery->submitted_at);
        $this->assertNull($delivery->last_error);

        $this->assertSame(['user/system/signin' => 1, 'album/add/meta' => 1, 's3' => 3, 'album/verify/meta' => 1, 'album/pdl/submit' => 1], $this->calls, 'token is cached');

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/album/add/meta' && $r->hasHeader('Authorization', 'Bearer cosmos-bearer-123')
            && $r['version'] === '2' && $r['albums'][0]['label'] === 'Test Records Pvt Ltd' && $r['albums'][0]['songs'][0]['data']['mood'] === 'Romantic');
        $this->assertSame([
            'inlay.jpg' => ['PUT', md5(Storage::disk('local')->get($this->release->artwork_path)), (string) strlen(Storage::disk('local')->get($this->release->artwork_path)), false],
            'one.wav' => ['PUT', md5($this->audioOne), (string) strlen($this->audioOne), false],
            'two.wav' => ['PUT', md5($this->audioTwo), (string) strlen($this->audioTwo), false],
        ], $this->uploads, 'streamed in one PUT each, with Content-Length, without the COSMOS token');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::API.'/album/verify/meta?') && $r['token'] === 'ingest-token-1' && $r['label_id'] === self::LABEL_ID);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/album/pdl/submit'
            && $r->data() === ['id' => self::ALBUM_ID, 'platforms_to_release' => 'AllPlatformNotOnYouTube', 'allow_any_go_live_date' => true]);

        $messages = $delivery->events()->pluck('message')->implode("\n");
        $this->assertStringContainsString('PDL accepted the metadata', $messages);
        $this->assertStringContainsString('PDL warning: Singer is empty', $messages);
        $this->assertStringContainsString('Submitted to PDL', $messages);
        $this->assertTrue(ActivityLog::query()->where('event', 'pdl.submitted')->where('user_id', $this->admin->id)->exists());

        // Tokens and presigned URLs are stored encrypted and never shown or logged.
        $raw = (string) \DB::table('pdl_deliveries')->value('ingest');
        $this->assertStringNotContainsString('secret2', $raw);
        $this->assertStringNotContainsString('ingest-token-1', $raw);
        $this->assertStringNotContainsString('X-Amz-Signature', $messages);
        $this->assertFalse(ActivityLog::query()->where('properties', 'like', '%X-Amz%')->orWhere('properties', 'like', '%cosmos-bearer%')->exists());
        $this->actingAs($this->admin)->get('/distribution/pdl/releases/'.$this->release->id)->assertOk()
            ->assertSee(self::ALBUM_ID)->assertSee('Submitted, PDL to review')->assertDontSee('X-Amz-Signature')->assertDontSee('ingest-token-1');

        // Submitted albums cannot be sent again.
        $this->expectException(DeliveryException::class);
        $this->send();
    }

    public function test_the_background_task_runs_deliveries_without_cron(): void
    {
        $this->configure();
        $this->fakePdl();
        $this->send();
        foreach (array_keys(AutomaticTasks::TASKS) as $task) {
            Cache::forever('labeldesk:auto:'.$task, now()->timestamp);
        }

        $this->assertSame(['pdl'], app(AutomaticTasks::class)->runDue(force: true));
        $this->assertSame('submitted', $this->delivery()->status);
    }

    public function test_nothing_runs_while_disabled(): void
    {
        $this->configure();
        $this->fakePdl();
        $this->send();
        app(PdlSettings::class)->save(['enabled' => false]);

        $this->assertSame(['processed' => 0, 'synced' => 0], app(DeliveryService::class)->run());
        Http::assertNothingSent();
        $this->assertSame('queued', $this->delivery()->status);
    }

    // ------------------------------------------------------------ retries and errors

    public function test_temporary_failures_are_retried_with_backoff_and_resume_where_they_stopped(): void
    {
        $this->configure();
        $this->fakePdl([
            'album/add/meta' => fn (Request $r, int $n) => $n === 1 ? Http::response('Bad gateway', 502) : Http::response(['success' => true, 'data' => ['token' => 'ingest-token-1', 'signed_albums' => [[
                'inlay' => ['signed_url' => 'https://s3.test/inlay.jpg?X-Amz-Signature=secret1'],
                'songs' => [['isrc' => 'INA1B2600001', 'media' => ['signed_url' => 'https://s3.test/one.wav?X-Amz-Signature=secret2']], ['isrc' => 'INA1B2600002', 'media' => ['signed_url' => 'https://s3.test/two.wav?X-Amz-Signature=secret3']]],
            ]]]]),
            's3' => fn (Request $r, int $n) => $n === 2
                ? Http::response('<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>', 500)
                : Http::response('', 200, ['ETag' => '"'.md5($r->body()).'"']),
        ]);
        $this->send();
        $service = app(DeliveryService::class);

        $service->run();
        $d = $this->delivery();
        $this->assertSame(['metadata', 'sending', 1], [$d->stage, $d->status, $d->attempts]);
        $this->assertStringContainsString('HTTP 502', $d->last_error);
        $this->assertEqualsWithDelta(now()->addMinute()->timestamp, $d->next_attempt_at->timestamp, 5);

        $service->run(); // not due yet
        $this->assertSame(1, $this->calls['album/add/meta']);

        $this->travel(2)->minutes();
        $service->run();
        $d = $this->delivery();
        $this->assertSame(['uploading', 1], [$d->stage, $d->attempts], 'second upload failed, first is kept');
        $this->assertSame([true, false, false], array_column(array_values($d->ingest['uploads']), 'done'));
        $this->assertStringContainsString('InternalError', $d->last_error);

        $this->travel(2)->minutes();
        $service->run();
        $d = $this->delivery();
        $this->assertSame(['done', 'submitted', 0], [$d->stage, $d->status, $d->attempts]);
        $this->assertSame(2, $this->calls['album/add/meta'], 'metadata is not sent again after the uploads started');
        $this->assertSame(4, $this->calls['s3'], 'artwork once, first master failed once and was uploaded again, second master once');
    }

    public function test_pdl_errors_stop_the_delivery_with_pdls_message_and_nothing_is_marked_delivered(): void
    {
        $this->configure();
        $this->fakePdl(['album/add/meta' => Http::response(['success' => false, 'data' => ['code' => -1, 'msg' => 'Duplicate ISRC']])]);
        $this->send();
        app(DeliveryService::class)->run();

        $d = $this->delivery();
        $this->assertSame('failed', $d->status);
        $this->assertSame('Duplicate ISRC', $d->last_error);
        $this->assertNull($d->pdl_album_id);
        $this->assertNull($d->submitted_at);
        $this->assertArrayNotHasKey('album/pdl/submit', $this->calls);
        $this->assertTrue(ActivityLog::query()->where('event', 'pdl.failed')->where('description', 'like', '%Duplicate ISRC%')->exists());
        Livewire::actingAs($this->admin)->test(ReleasePage::class, ['release' => $this->release])->assertSee('Stopped:')->assertSee('Duplicate ISRC')->assertSee('Retry');

        // Retries stop after the backoff steps too.
        $this->fakePdl(['album/add/meta' => Http::response('down', 503)]);
        app(DeliveryService::class)->retry($this->delivery(), $this->admin);
        foreach (range(1, 6) as $i) {
            app(DeliveryService::class)->run();
            $this->travel(181)->minutes();
        }
        $d = $this->delivery();
        $this->assertSame('failed', $d->status);
        $this->assertSame(6, $this->calls['album/add/meta']);
        $this->assertNull($d->submitted_at);
    }

    public function test_a_refused_submission_is_not_marked_submitted_and_retry_only_resubmits(): void
    {
        $this->configure();
        $this->fakePdl(['album/pdl/submit' => Http::response(['success' => false, 'data' => ['code' => 0, 'msg' => 'Sub-label is not verified']])]);
        $this->send();
        app(DeliveryService::class)->run();

        $d = $this->delivery();
        $this->assertSame(['submitting', 'failed'], [$d->stage, $d->status]);
        $this->assertSame(self::ALBUM_ID, $d->pdl_album_id);
        $this->assertNull($d->submitted_at);
        $this->assertSame('Sub-label is not verified', $d->last_error);

        $this->fakePdl();
        app(DeliveryService::class)->retry($d, $this->admin);
        app(DeliveryService::class)->run();
        $this->assertSame('submitted', $this->delivery()->status);
        $this->assertSame(['album/pdl/submit' => 1], $this->calls, 'nothing uploaded twice, token still cached');
    }

    public function test_an_expired_token_is_renewed_once(): void
    {
        $this->configure();
        $this->fakePdl([
            'user/system/signin' => fn (Request $r, int $n) => Http::response(['success' => true, 'data' => ['token' => 'cosmos-token-'.$n]]),
            'album/add/meta' => fn (Request $r, int $n) => $r->hasHeader('Authorization', 'Bearer cosmos-token-1')
                ? Http::response(['success' => false, 'data' => ['code' => 403, 'msg' => 'Access denied']], 401)
                : Http::response(['success' => false, 'data' => ['code' => -1, 'msg' => 'Duplicate ISRC']]),
        ]);
        $this->send();
        app(DeliveryService::class)->run();

        $this->assertSame(2, $this->calls['user/system/signin']);
        $this->assertSame(2, $this->calls['album/add/meta']);
        $this->assertSame('Duplicate ISRC', $this->delivery()->last_error);
    }

    public function test_upload_checksum_mismatch_retries_and_expired_links_stop(): void
    {
        $this->configure();
        $this->fakePdl(['s3' => fn (Request $r, int $n) => match (true) {
            $n === 1 => Http::response('', 200, ['ETag' => '"00000000000000000000000000000000"']),
            str_contains($r->url(), 'two.wav') => Http::response('<Error><Code>AccessDenied</Code><Message>Request has expired</Message></Error>', 403),
            default => Http::response('', 200, ['ETag' => '"'.md5($r->body()).'"']),
        }]);
        $this->send();
        $service = app(DeliveryService::class);

        $service->run();
        $this->assertStringContainsString('does not match', $this->delivery()->last_error);
        $this->assertSame('uploading', $this->delivery()->stage);

        $this->travel(2)->minutes();
        $service->run();
        $d = $this->delivery();
        $this->assertSame('failed', $d->status);
        $this->assertSame('The upload link from PDL has expired. Send the release again to get new links.', $d->last_error);
        $this->assertNull($d->pdl_album_id);
        $this->assertArrayNotHasKey('album/verify/meta', $this->calls);
    }

    // ------------------------------------------------------------ status

    public function test_status_sync_follows_review_issues_and_release(): void
    {
        $this->configure();
        $this->fakePdl();
        $this->send();
        $service = app(DeliveryService::class);
        $service->run();

        // PDL reports an issue.
        $this->fakePdl(album: ['submitted' => false, 'reportissue_flag' => true]);
        Livewire::actingAs($this->admin)->test(ReleasePage::class, ['release' => $this->release])->call('checkStatus')
            ->assertDispatched('toast', type: 'success', message: 'PDL status: Issue reported by PDL.');
        $d = $this->delivery();
        $this->assertSame('issue_reported', $d->status);
        $this->assertSame('12/20/2026: Artwork has a DSP logo.', $d->issue);
        $this->assertTrue(ActivityLog::query()->where('event', 'pdl.status_changed')->exists());

        // Periodic sync: not before an hour has passed.
        $this->fakePdl(album: ['submitted' => true, 'verified' => true, 'releases' => [
            ['platform_id' => '5ea6bee7579fb93515c02ada', 'release_status' => 2, 'live_link' => 'https://music.example/album/1', 'release_error' => ''],
            ['platform_id' => '5ea6bee7579fb93515c02aef', 'release_status' => 1, 'live_link' => '', 'release_error' => ''],
        ]]);
        $this->assertSame(0, $service->run()['synced']);
        $this->travel(61)->minutes();
        $this->assertSame(1, $service->run()['synced']);

        $d = $this->delivery();
        $this->assertSame('live', $d->status);
        $this->assertNotNull($d->live_at);
        $this->assertNull($d->issue);
        $this->assertSame([2, 1], array_column($d->pdl_state['releases'], 'release_status'));

        // Live albums are checked daily: a release error at a store.
        $this->travel(61)->minutes();
        $this->assertSame(0, $service->run()['synced']);
        $this->travel(1440)->minutes();
        // As on staging: the listing names the per-platform status releases_res.
        $this->fakePdl(album: ['verified' => true, 'releases' => null, 'releases_res' => [['platform_id' => 'x', 'release_status' => 9, 'release_error' => 'Converter error']]]);
        $service->run();
        $this->assertSame(['release_error', 'Converter error'], [$this->delivery()->status, $this->delivery()->issue]);
    }

    public function test_an_album_with_a_reported_issue_can_be_updated_and_resubmitted(): void
    {
        $this->configure();
        $this->fakePdl();
        $this->send();
        $service = app(DeliveryService::class);
        $service->run();
        $this->fakePdl(album: ['submitted' => false, 'reportissue_flag' => true]);
        $service->syncStatus($this->delivery());
        $this->assertSame('issue_reported', $this->delivery()->status);

        // New artwork and a corrected title.
        $this->one->forceFill(['title' => 'Glass City (Fixed)'])->save();
        $img = imagecreatetruecolor(1600, 1600);
        ob_start();
        imagepng($img);
        Storage::disk('local')->put('releases/'.$this->release->id.'/artwork-2-original.png', (string) ob_get_clean());
        $this->release->forceFill(['artwork_original_path' => 'releases/'.$this->release->id.'/artwork-2-original.png'])->save();

        $this->fakePdl(album: ['submitted' => false, 'reportissue_flag' => true]);
        $d = $this->send();
        $this->assertSame(['update', 'updating'], [$d->action, $d->stage]);
        $service->run();

        $d = $this->delivery();
        $this->assertSame(['done', 'submitted'], [$d->stage, $d->status]);
        $this->assertArrayNotHasKey('album/add/meta', $this->calls);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::API.'/album/media/signedurl?') && $r['type'] === 'Artwork' && $r['id'] === self::ALBUM_ID);
        $this->assertSame(md5(Storage::disk('local')->get($this->release->artwork_original_path)), $this->uploads['replace.jpg'][1]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::API.'/album/media/update'
            && $r['one'] === 'media' && $r['album']['id'] === self::ALBUM_ID && $r['album']['songs'][0]['id'] === '6694fb0b579fb93bb54d9e8c'
            && $r['album']['songs'][0]['title'] === 'Glass City (Fixed)' && $r['album']['songs'][0]['crbt_cuts'] === [['name' => 'Glass City']]
            && $r['album']['go_live_date'] === '2026-12-24T00:00:00.000Z' && $r['album']['songs'][0]['go_live_date'] === '2026-12-24T00:00:00.000Z'
            && ! isset($r['album']['artwork']) && ! isset($r['album']['songs'][0]['media']) && ! isset($r['album']['songs'][0]['crbt_cuts'][0]['crbt_cut_media']));
        $this->assertSame(1, $this->calls['album/pdl/submit']);
    }

    public function test_the_cosmos_token_is_read_from_oauth2_token_and_cached_until_its_expiry(): void
    {
        $this->configure();
        $this->fakePdl(['user/system/signin' => fn (Request $r, int $n) => Http::response($this->signInAnswer('cosmos-token-'.$n, $n === 1 ? '+2 days' : '+3 minutes'))]);
        $client = app(CosmosClient::class);

        $this->assertSame('cosmos-token-1', $client->token());
        $this->travel(2)->hours();
        $this->assertSame('cosmos-token-1', $client->token(), 'cached past the 50-minute default: PDL said it lasts 2 days');
        $this->travel(2)->days();
        $this->assertSame('cosmos-token-2', $client->token(), 'expired: signed in again');
        $this->assertSame(2, $this->calls['user/system/signin']);

        // Cached encrypted.
        $cached = (string) Cache::get('pdl:token:'.sha1('https://pdl.test|label@example.com'));
        $this->assertStringNotContainsString('cosmos-token-2', $cached);
        $this->assertSame('cosmos-token-2', Crypt::decryptString($cached));
    }
}

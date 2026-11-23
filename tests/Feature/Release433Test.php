<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Livewire\Plugins\Index as PluginsIndex;
use App\Livewire\System\Updates;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginPackage;
use App\Services\SettingsService;
use App\Services\System\UpgradeService;
use App\Services\Updates\CorePackage;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\UpdateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * 4.3.3: add-on tables on install, automatic database upgrade after new files,
 * over-the-air updates for LabelDesk and add-ons.
 */
class Release433Test extends TestCase
{
    use RefreshDatabase;

    protected const API = 'https://musicdesk.tech/api/license/v1';

    protected User $admin;

    protected string $secret;

    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->work = str_replace('\\', '/', sys_get_temp_dir()).'/ld-433-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->work.'/plugins');
        config([
            'licensing.testing.plugin_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'licensing.testing.api_base_url' => self::API,
            'licensing.testing.updates' => true,
            'labeldesk.plugins_path' => $this->work.'/plugins',
            'labeldesk.updates.backup_first' => false,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        app(UpgradeService::class)->clearFailure();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        app(UpgradeService::class)->clearFailure();
        app(UpgradeService::class)->release();
        @unlink(storage_path('framework/labeldesk-update-progress.json'));
        parent::tearDown();
    }

    protected function addonZip(string $version = '1.0.0'): string
    {
        $source = $this->work.'/src-'.bin2hex(random_bytes(3));
        File::copyDirectory(base_path('tests/Fixtures/plugins/hello-addon'), $source);
        $data = json_decode(file_get_contents($source.'/plugin.json'), true);
        file_put_contents($source.'/plugin.json', json_encode(['version' => $version] + $data, JSON_UNESCAPED_SLASHES));
        $zip = $this->work.'/addon-'.$version.'-'.bin2hex(random_bytes(3)).'.zip';
        PluginPackage::build($source, $this->secret, $zip);

        return $zip;
    }

    /** A small signed LabelDesk package. */
    protected function coreZip(string $version, array $files, ?string $secret = null): string
    {
        $stage = $this->work.'/stage-'.bin2hex(random_bytes(3));
        $defaults = [];
        foreach (range(1, 9) as $i) {
            $defaults["app/Generated/File{$i}.php"] = "<?php // {$version} {$i}\n";
        }
        foreach ($files + $defaults as $path => $content) {
            File::ensureDirectoryExists(dirname($stage.'/'.$path));
            file_put_contents($stage.'/'.$path, $content);
        }
        CorePackage::sign($stage, $version, $secret ?? $this->secret);

        $zipPath = $this->work.'/labeldesk-'.$version.'-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1));
            $zip->addFile($file->getPathname(), 'labeldesk-'.$version.'/'.$relative);
        }
        $zip->close();

        return $zipPath;
    }

    protected array $checkAnswer = [];

    protected array $downloads = [];

    protected bool $faked = false;

    protected function fakeServer(array $check, array $downloads = []): void
    {
        $this->checkAnswer = $check;
        $this->downloads = $downloads;
        if ($this->faked) {
            return; // a second Http::fake() would not replace the first
        }
        $this->faked = true;

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/updates/check')) {
                return Http::response($this->checkAnswer);
            }
            if (str_ends_with($request->url(), '/updates/download')) {
                $file = $this->downloads[$request['product'].'@'.$request['version']] ?? null;

                return $file ? Http::response(file_get_contents($file), 200, ['Content-Type' => 'application/zip'])
                    : Http::response(['error' => ['code' => 'updates_expired', 'message' => 'Your updates period has ended.']], 403);
            }

            return Http::response(['error' => ['code' => 'not_found', 'message' => 'x']], 404);
        });
    }

    protected function release(string $version, array $extra = []): array
    {
        return $extra + ['version' => $version, 'released_at' => '2026-12-01', 'changelog' => "### Added\n- Better things", 'downloadable' => true];
    }

    // ------------------------------------------------------------ 1. add-on tables on install

    public function test_installing_an_add_on_creates_its_tables(): void
    {
        $plugin = app(PluginManager::class)->install($this->addonZip());

        $this->assertFalse($plugin->active);
        $this->assertTrue(Schema::hasTable('hello_notes'));
    }

    // ------------------------------------------------------------ 3. automatic database upgrade

    public function test_new_files_upgrade_the_database_on_the_first_request(): void
    {
        config(['labeldesk.testing.auto_upgrade' => true]);
        app(SettingsService::class)->set('system.installed_version', '4.3.2');

        $this->get('/login')->assertStatus(503)->assertSee('is being updated')->assertHeader('Retry-After', '5');
        // The upgrade ran after the response (terminating callback).
        $this->assertSame(config('labeldesk.product.version'), app(SettingsService::class)->get('system.installed_version'));
        $this->get('/login')->assertOk();

        app(SettingsService::class)->set('system.installed_version', '4.3.2');
        $this->postJson('/livewire/update', [])->assertStatus(503)->assertJson(['message' => 'LabelDesk is being updated. Try again in a moment.']);
    }

    public function test_a_failed_upgrade_lets_the_site_run_and_is_shown_to_administrators(): void
    {
        config(['labeldesk.testing.auto_upgrade' => true]);
        app(SettingsService::class)->set('system.installed_version', '4.3.2');
        $this->app->bind(UpgradeService::class, fn ($app) => new class($app->make(\Illuminate\Database\Migrations\Migrator::class), $app->make(SettingsService::class), $app->make(\App\Services\Activity\ActivityLogger::class)) extends UpgradeService
        {
            public function run(?User $user = null, bool $automatic = false): array
            {
                throw new RuntimeException('Disk full');
            }
        });

        $this->get('/login')->assertStatus(503);
        $this->assertSame('Disk full', app(UpgradeService::class)->failure()['error']);

        // Not retried for 15 minutes: the site runs meanwhile.
        $this->get('/login')->assertOk();
        $this->actingAs($this->admin)->get(route('system.index'))->assertOk()->assertSee('Disk full');
    }

    public function test_the_upgrade_clears_every_cache(): void
    {
        cache()->put('something', 'stale');
        app(UpgradeService::class)->run($this->admin);

        $this->assertNull(cache()->get('something'));
        $this->assertSame(config('labeldesk.product.version'), app(UpgradeService::class)->installedVersion());
    }

    // ------------------------------------------------------------ 2. over-the-air updates

    public function test_check_shows_available_updates_with_a_menu_badge(): void
    {
        app(PluginManager::class)->install($this->addonZip());
        $this->fakeServer([
            'labeldesk' => $this->release('9.0.0', ['requires_php' => '99.0']),
            'addons' => ['rest_api' => $this->release('1.1.0')],
        ]);

        $status = app(UpdateService::class)->check();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/updates/check') && $r['version'] === config('labeldesk.product.version')
            && $r['addons'][0]['addon'] === 'rest_api' && is_string($r['token']));
        $this->assertSame('9.0.0', $status['labeldesk']['version']);
        $this->assertSame(2, app(UpdateService::class)->availableCount());

        $page = $this->actingAs($this->admin)->get(route('updates.index'))->assertOk();
        $page->assertSee('Version 9.0.0 is available')->assertSee('needs PHP 99.0')->assertSee('Update to 1.1.0');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('2 updates available');

        $this->actingAs($this->makeUser(SystemRole::LabelManager))->get(route('updates.index'))->assertForbidden();
    }

    public function test_an_add_on_updates_over_the_air_and_keeps_running(): void
    {
        $plugin = app(PluginManager::class)->install($this->addonZip());
        $newZip = $this->addonZip('1.1.0');
        $this->fakeServer(['labeldesk' => null, 'addons' => ['rest_api' => $this->release('1.1.0', ['sha256' => hash_file('sha256', $newZip)])]],
            ['rest_api@1.1.0' => $newZip]);
        app(UpdateService::class)->check();

        Livewire::actingAs($this->admin)->test(PluginsIndex::class)
            ->assertSee('1.1.0 available')->call('updateAddon', $plugin->id)->assertDispatched('toast', type: 'success');

        $this->assertSame('1.1.0', $plugin->fresh()->version);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/updates/download') && $r['product'] === 'rest_api' && $r['version'] === '1.1.0');
        $this->assertSame(0, app(UpdateService::class)->availableCount());
    }

    public function test_a_damaged_or_refused_download_changes_nothing(): void
    {
        $plugin = app(PluginManager::class)->install($this->addonZip());
        $newZip = $this->addonZip('1.1.0');
        $this->fakeServer(['addons' => ['rest_api' => $this->release('1.1.0', ['sha256' => str_repeat('a', 64)])]], ['rest_api@1.1.0' => $newZip]);
        app(UpdateService::class)->check();

        try {
            app(UpdateService::class)->installAddon($plugin);
            $this->fail('checksum mismatch must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertSame('1.0.0', $plugin->fresh()->version);

        // Updates period over: the server refuses the download.
        $this->fakeServer(['addons' => ['rest_api' => $this->release('1.1.0', ['downloadable' => false, 'reason' => 'Your updates period has ended.'])]]);
        app(UpdateService::class)->check();
        Livewire::actingAs($this->admin)->test(Updates::class)->assertSee('Your updates period has ended.')->assertDontSee('Update to 1.1.0');
    }

    public function test_automatic_updates_only_for_what_the_administrator_chose(): void
    {
        $plugin = app(PluginManager::class)->install($this->addonZip());
        $newZip = $this->addonZip('1.1.0');
        $this->fakeServer(['addons' => ['rest_api' => $this->release('1.1.0')]], ['rest_api@1.1.0' => $newZip]);

        $this->assertSame([], app(UpdateService::class)->runAutomatic(), 'off by default');
        $this->assertSame('1.0.0', $plugin->fresh()->version);

        Livewire::actingAs($this->admin)->test(Updates::class)->call('toggleAutoAddon', $plugin->id);
        $this->assertTrue($plugin->fresh()->auto_update);
        $this->assertSame(['hello-addon 1.1.0'], app(UpdateService::class)->runAutomatic());
        $this->assertSame('1.1.0', $plugin->fresh()->version);

        Livewire::actingAs($this->admin)->test(Updates::class)->call('toggleAutoCore');
        $this->assertTrue(app(UpdateService::class)->autoCore());
    }

    public function test_updates_are_only_offered_to_online_musicdesk_licenses(): void
    {
        config(['licensing.testing.updates' => false]); // the mock (test key) provider
        Http::fake();

        $this->assertNotNull(app(UpdateService::class)->availability());
        app(UpdateService::class)->check();
        Http::assertNothingSent();
        $this->actingAs($this->admin)->get(route('updates.index'))->assertOk()->assertSee('activated online with a MusicDesk.tech license');
    }

    // ------------------------------------------------------------ core package and installer

    public function test_core_packages_must_be_signed_and_intact(): void
    {
        $good = $this->coreZip('9.0.0', ['config/app.php' => '<?php return [];']);
        $package = CorePackage::open($good, '9.0.0');
        $this->assertContains('config/app.php', $package->files());
        $package->close();

        $other = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        foreach ([
            'not signed by us' => $this->coreZip('9.0.0', [], $other),
            'tampered' => tap($this->coreZip('9.0.0', []), function ($zip) {
                $z = new ZipArchive;
                $z->open($zip);
                $z->addFromString('labeldesk-9.0.0/app/Generated/File1.php', '<?php evil();');
                $z->close();
            }),
        ] as $case => $zip) {
            try {
                CorePackage::open($zip);
                $this->fail("{$case} must be refused");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(RuntimeException::class);
        CorePackage::open($good, '9.0.1');
    }

    public function test_core_update_replaces_files_but_keeps_env_storage_plugins_and_htaccess(): void
    {
        $root = $this->work.'/site';
        foreach ([
            '.env' => 'APP_KEY=secret', '.htaccess' => '# customer rules', 'app/Old.php' => 'old', 'config/app.php' => 'old config',
            'storage/app/keep.txt' => 'data', 'plugins/x/plugin.json' => '{}',
        ] as $path => $content) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            file_put_contents($root.'/'.$path, $content);
        }
        $zip = $this->coreZip('9.0.0', [
            '.env' => 'APP_KEY=stolen', '.htaccess' => '# new', 'config/app.php' => 'new config', 'app/New.php' => 'new',
            'storage/app/keep.txt' => 'overwritten', 'plugins/x/plugin.json' => 'overwritten',
        ]);

        $package = CorePackage::open($zip);
        $result = (new CoreUpdater($root))->install($package);
        $package->close();

        $this->assertSame('new config', file_get_contents($root.'/config/app.php'));
        $this->assertSame('new', file_get_contents($root.'/app/New.php'));
        $this->assertSame('old', file_get_contents($root.'/app/Old.php'), 'files not in the package are left');
        $this->assertSame('APP_KEY=secret', file_get_contents($root.'/.env'));
        $this->assertSame('# customer rules', file_get_contents($root.'/.htaccess'));
        $this->assertSame('data', file_get_contents($root.'/storage/app/keep.txt'));
        $this->assertSame('{}', file_get_contents($root.'/plugins/x/plugin.json'));
        $this->assertSame(['.htaccess'], $result['kept']);
        $this->assertSame('old config', file_get_contents($result['rollback'].'/config/app.php'), 'previous files kept for rollback');
        $this->assertFileDoesNotExist($root.'/storage/framework/down', 'maintenance mode is off again');
    }

    public function test_a_failed_core_update_puts_every_file_back(): void
    {
        $root = $this->work.'/site';
        File::ensureDirectoryExists($root.'/app/Generated');
        foreach (range(1, 9) as $i) {
            file_put_contents($root."/app/Generated/File{$i}.php", "old {$i}");
        }
        $package = CorePackage::open($this->coreZip('9.0.0', []));

        $updater = new class($root) extends CoreUpdater
        {
            public int $moves = 0;

            protected function move(string $from, string $to): void
            {
                if (++$this->moves === 9) {
                    throw new RuntimeException('disk error');
                }
                parent::move($from, $to);
            }
        };

        try {
            $updater->install($package);
            $this->fail('the update must fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
        } finally {
            $package->close();
        }

        foreach (range(1, 9) as $i) {
            $this->assertSame("old {$i}", file_get_contents($root."/app/Generated/File{$i}.php"));
        }
        $this->assertFileDoesNotExist($root.'/storage/framework/down');
    }

    public function test_labeldesk_updates_over_the_air(): void
    {
        $root = $this->work.'/site';
        File::ensureDirectoryExists($root.'/config');
        file_put_contents($root.'/config/app.php', 'old');
        config(['labeldesk.updates.root' => $root]);

        $zip = $this->coreZip('9.0.0', ['config/app.php' => 'new']);
        $this->fakeServer(['labeldesk' => $this->release('9.0.0', ['sha256' => hash_file('sha256', $zip)])], ['labeldesk@9.0.0' => $zip]);
        app(UpdateService::class)->check();

        Livewire::actingAs($this->admin)->test(Updates::class)
            ->assertSee('Version 9.0.0 is available')->call('installCore')->assertDispatched('update-started');
        app()->terminate(); // the install runs after the response

        $this->assertSame('new', file_get_contents($root.'/config/app.php'));
        $this->assertSame('done', app(UpdateService::class)->progress()['state']);
        $this->actingAs($this->admin)->getJson(route('updates.progress'))->assertOk()->assertJson(['state' => 'done']);
    }
}

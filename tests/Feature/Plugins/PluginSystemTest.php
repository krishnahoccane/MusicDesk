<?php

namespace Tests\Feature\Plugins;

use App\Enums\SystemRole;
use App\Livewire\Plugins\Index;
use App\Models\LicenseActivation;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\AddonLicenseService;
use App\Plugins\PluginException;
use App\Plugins\PluginManager;
use App\Plugins\PluginPackage;
use App\Services\License\LicenseAuthority;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ZipArchive;

class PluginSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $secret;

    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->work = sys_get_temp_dir().'/ld-plugins-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->work.'/installed');
        config([
            'licensing.testing.plugin_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'labeldesk.plugins_path' => $this->work.'/installed',
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    /** Copy the fixture, optionally change it, and build a signed ZIP. */
    protected function package(array $manifest = [], ?string $secret = null, ?callable $change = null): string
    {
        $source = $this->work.'/src-'.bin2hex(random_bytes(3));
        File::copyDirectory(base_path('tests/Fixtures/plugins/hello-addon'), $source);
        if ($manifest) {
            $data = array_merge(json_decode(file_get_contents($source.'/plugin.json'), true), $manifest);
            file_put_contents($source.'/plugin.json', json_encode($data, JSON_UNESCAPED_SLASHES));
        }
        $zip = $this->work.'/'.bin2hex(random_bytes(3)).'.zip';
        PluginPackage::build($source, $secret ?? $this->secret, $zip);
        $change && $change($zip);

        return $zip;
    }

    protected const ADDON_KEY = 'MD-LD-TEST-ADDN-AAAA-BBBB';

    protected function manager(): PluginManager
    {
        return app(PluginManager::class);
    }

    public function test_a_signed_package_installs_inactive(): void
    {
        $plugin = $this->manager()->install($this->package());

        $this->assertSame('hello-addon', $plugin->slug);
        $this->assertSame('rest_api', $plugin->addon);
        $this->assertFalse($plugin->active);
        $this->assertFileExists($this->work.'/installed/hello-addon/src/HelloServiceProvider.php');
        $this->assertFileExists($this->work.'/installed/hello-addon/signature.json');
        $this->assertFalse(Schema::hasTable('hello_notes'), 'nothing runs before activation');
    }

    public function test_tampered_unsigned_or_foreign_packages_are_refused(): void
    {
        $tampered = $this->package(change: function (string $zip) {
            $z = new ZipArchive;
            $z->open($zip);
            $z->addFromString('hello-addon/routes/web.php', '<?php system($_GET["c"]);');
            $z->close();
        });
        $extra = $this->package(change: function (string $zip) {
            $z = new ZipArchive;
            $z->open($zip);
            $z->addFromString('hello-addon/src/Backdoor.php', '<?php');
            $z->close();
        });
        $unsigned = $this->package(change: function (string $zip) {
            $z = new ZipArchive;
            $z->open($zip);
            $z->deleteName('hello-addon/signature.json');
            $z->close();
        });
        $foreign = $this->package(secret: base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));

        foreach (['changed after signing' => $tampered, 'did not sign' => $extra, 'not signed' => $unsigned, 'signature is not valid' => $foreign] as $message => $zip) {
            try {
                $this->manager()->install($zip);
                $this->fail("Expected refusal: $message");
            } catch (PluginException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertDirectoryDoesNotExist($this->work.'/installed/hello-addon');
        $this->assertSame(0, Plugin::query()->count());
    }

    public function test_path_traversal_is_refused(): void
    {
        $zip = $this->work.'/evil.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('hello-addon/plugin.json', '{}');
        $z->addFromString('hello-addon/../../evil.php', '<?php');
        $z->close();

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('unsafe file name');
        $this->manager()->install($zip);
    }

    public function test_activation_runs_migrations_routes_menu_and_permissions_then_deactivation_stops_it(): void
    {
        $plugin = $this->manager()->install($this->package());
        $this->manager()->activate($plugin, self::ADDON_KEY);

        $this->assertTrue($plugin->fresh()->active);
        $this->assertSame('active', $plugin->fresh()->license_status);
        $this->assertStringNotContainsString(self::ADDON_KEY, (string) $plugin->fresh()->getRawOriginal('license_key'), 'key stored encrypted');
        $this->assertTrue(Schema::hasTable('hello_notes'));
        $this->assertSame('activated', \DB::table('hello_notes')->value('note'), 'activate() hook ran');
        $this->assertTrue(Permission::query()->where('name', 'hello.view')->exists());

        $this->actingAs($this->admin)->get('/hello')->assertOk()->assertSee('Hello from an add-on');
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee(route('hello.index'));
        $this->postJson('/api/hello/ping')->assertOk()->assertJson(['pong' => true]); // stateless, no CSRF

        $manager = $this->makeUser(SystemRole::LabelManager);
        $this->actingAs($manager)->get('/hello')->assertForbidden();

        $this->manager()->deactivate($plugin);
        $this->actingAs($this->admin)->get('/hello')->assertNotFound();
        $this->assertTrue(Schema::hasTable('hello_notes'), 'data is kept');
    }

    public function test_an_add_on_needs_its_own_valid_key(): void
    {
        $plugin = $this->manager()->install($this->package());

        foreach ([null => 'Enter the license key', 'MD-LD-TEST-REVK-AAAA-BBBB' => 'revoked', 'not a key!' => 'not recognised'] as $key => $message) {
            try {
                $this->manager()->activate($plugin->fresh(), $key === '' ? null : $key);
                $this->fail("Expected refusal for [$key]");
            } catch (PluginException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertFalse($plugin->fresh()->active);

        // A token for another add-on or domain is not accepted, even if stored.
        $this->manager()->activate($plugin->fresh(), self::ADDON_KEY);
        $other = Plugin::query()->create(['slug' => 'other', 'name' => 'Other', 'version' => '1.0.0', 'addon' => 'sso', 'manifest' => [],
            'license_token' => $plugin->fresh()->license_token, 'license_key' => self::ADDON_KEY, 'license_status' => 'active']);
        $this->assertFalse($this->manager()->licensed($other));
        config(['app.url' => 'https://another-label.example']);
        LicenseActivation::query()->update(['application_url' => 'https://another-label.example']);
        $this->assertFalse($this->manager()->licensed($plugin->fresh()));

        // Marked active but without a valid key it does not start.
        $fresh = new PluginManager($this->app);
        $fresh->boot();
        $this->assertFalse($fresh->isRunning('hello-addon'));
    }

    public function test_a_revoked_key_pauses_the_add_on_at_the_hourly_check(): void
    {
        $plugin = $this->manager()->install($this->package());
        $this->manager()->activate($plugin, self::ADDON_KEY);
        $this->assertTrue($this->manager()->licensed($plugin->fresh()));

        $plugin->fresh()->forceFill(['license_status' => 'revoked'])->save(); // as stored after a "license_revoked" answer
        $this->assertFalse($this->manager()->licensed($plugin->fresh()));

        // Hourly check-in renews a good key.
        $plugin->fresh()->forceFill(['license_status' => 'active', 'license_checked_at' => now()->subHours(2)])->save();
        app(AddonLicenseService::class)->refreshDue();
        $this->assertTrue($plugin->fresh()->license_checked_at->isAfter(now()->subMinute()));
        $this->assertTrue($this->manager()->licensed($plugin->fresh()));
    }

    public function test_updates_downgrades_and_delete(): void
    {
        $plugin = $this->manager()->install($this->package());
        $this->manager()->install($this->package(['version' => '1.1.0']));
        $this->assertSame('1.1.0', $plugin->fresh()->version);

        $this->expectExceptionObject(new PluginException(__('Version :new is older than the installed :old.', ['new' => '1.0.0', 'old' => '1.1.0'])));
        try {
            $this->manager()->install($this->package());
        } finally {
            $this->manager()->activate($plugin->fresh(), self::ADDON_KEY);
            try {
                $this->manager()->delete($plugin->fresh());
                $this->fail('Active add-ons cannot be deleted');
            } catch (PluginException) {
            }
            $this->manager()->deactivate($plugin->fresh());
            $this->manager()->delete($plugin->fresh());
            $this->assertDirectoryDoesNotExist($this->work.'/installed/hello-addon');
            $this->assertSame(0, Plugin::query()->count());
        }
    }

    public function test_requires_a_newer_labeldesk(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('needs LabelDesk 9.0.0');
        $this->manager()->install($this->package(['requires' => '9.0.0']));
    }

    public function test_admin_page_upload_and_activate(): void
    {
        $this->actingAs($this->makeUser(SystemRole::LabelManager))->get(route('plugins.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('plugins.index'))->assertOk()
            ->assertSee('Upload add-on')->assertSee(LicenseAuthority::ADDONS_URL);

        $file = UploadedFile::fake()->createWithContent('hello-addon-1.0.0.zip', file_get_contents($this->package()));
        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('package', $file)->call('upload')->assertHasNoErrors()->assertDispatched('toast', type: 'success')
            ->assertSee('Hello add-on');

        $plugin = Plugin::query()->sole();
        Livewire::actingAs($this->admin)->test(Index::class)->call('activate', $plugin->id)->assertHasErrors('keys.'.$plugin->id)
            ->set('keys.'.$plugin->id, 'MD-LD-TEST-REVK-AAAA-BBBB')->call('activate', $plugin->id)->assertHasErrors('keys.'.$plugin->id);
        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('keys.'.$plugin->id, self::ADDON_KEY)->call('activate', $plugin->id)->assertRedirect(route('plugins.index'));
        $this->assertTrue($plugin->fresh()->active);

        Livewire::actingAs($this->admin)->test(Index::class)->call('deactivate', $plugin->id);
        $this->assertFalse($plugin->fresh()->active);

        $bad = UploadedFile::fake()->createWithContent('bad.zip', 'not a zip');
        Livewire::actingAs($this->admin)->test(Index::class)->set('package', $bad)->call('upload')->assertHasErrors('package');
    }
}

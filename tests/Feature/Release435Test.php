<?php

namespace Tests\Feature;

use App\Services\Updates\CorePackage;
use App\Services\Updates\CoreUpdater;
use App\Support\StaleCacheGuard;
use App\Support\Text;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * 4.3.5: after new files are uploaded (update or FTP), Laravel's cached package and
 * config lists of the previous version must not stop the application from starting.
 */
class Release435Test extends TestCase
{
    protected string $work;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        config(['licensing.testing.plugin_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $this->work = str_replace('\\', '/', sys_get_temp_dir()).'/ld-435-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    protected function site(): string
    {
        $root = $this->work.'/site';
        foreach (['bootstrap/cache', 'vendor/composer', 'config', 'routes', 'app/Providers'] as $dir) {
            File::ensureDirectoryExists($root.'/'.$dir);
        }
        file_put_contents($root.'/vendor/composer/installed.php', '<?php return [];');
        file_put_contents($root.'/config/app.php', '<?php return [];');
        file_put_contents($root.'/routes/web.php', '<?php');

        return $root;
    }

    protected function packages(string $root, string $provider, int $mtime): void
    {
        file_put_contents($root.'/bootstrap/cache/packages.php', "<?php return ['x/y' => ['providers' => ['".addslashes($provider)."']]];");
        file_put_contents($root.'/bootstrap/cache/services.php', '<?php return [];');
        touch($root.'/bootstrap/cache/packages.php', $mtime);
        touch($root.'/bootstrap/cache/services.php', $mtime);
    }

    public function test_package_lists_naming_a_missing_provider_are_rebuilt(): void
    {
        $root = $this->site();
        touch($root.'/vendor/composer/installed.php', time() - 3600);

        // The demo server's case: a development package (Laravel Pail) listed, not in the release vendor/.
        $this->packages($root, 'Laravel\\Pail\\Missing\\PailServiceProvider', time());
        $this->assertSame(['packages.php', 'services.php'], StaleCacheGuard::check($root));
        $this->assertFileDoesNotExist($root.'/bootstrap/cache/packages.php');

        // Up-to-date lists of existing providers are kept.
        $this->packages($root, \Illuminate\Foundation\Support\Providers\RouteServiceProvider::class, time());
        $this->assertSame([], StaleCacheGuard::check($root));
        $this->assertFileExists($root.'/bootstrap/cache/packages.php');

        // vendor/ newer than the lists (files uploaded): rebuilt.
        touch($root.'/vendor/composer/installed.php', time() + 5);
        $this->assertSame(['packages.php', 'services.php'], StaleCacheGuard::check($root));
    }

    public function test_cached_config_and_routes_older_than_the_files_are_removed(): void
    {
        $root = $this->site();
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($root.'/bootstrap/cache/routes-v7.php', '<?php');
        touch($root.'/bootstrap/cache/config.php', time() - 60);
        touch($root.'/bootstrap/cache/routes-v7.php', time() - 60);

        $this->assertSame(['config.php', 'routes-v7.php'], StaleCacheGuard::check($root));
    }

    public function test_the_update_removes_dropped_files_and_laravel_caches(): void
    {
        $root = $this->site();
        File::ensureDirectoryExists($root.'/app/Old');
        file_put_contents($root.'/app/Old/Gone.php', 'old');
        file_put_contents($root.'/app/Kept.php', 'old');
        file_put_contents($root.'/bootstrap/cache/packages.php', '<?php return [];');
        file_put_contents($root.'/bootstrap/cache/routes-v7.php', '<?php');

        // The installed version's signed file list (written by the previous update or shipped in the zip).
        $old = $this->stage('9.0.0', ['app/Old/Gone.php' => 'old', 'app/Kept.php' => 'old']);
        copy($old.'/signature.json', $root.'/signature.json');

        $zip = $this->zip($this->stage('9.0.1', ['app/Kept.php' => 'new']), '9.0.1');
        $package = CorePackage::open($zip, '9.0.1');
        $result = (new CoreUpdater($root))->install($package);
        $package->close();

        $this->assertSame('new', file_get_contents($root.'/app/Kept.php'));
        $this->assertFileDoesNotExist($root.'/app/Old/Gone.php', 'code the new version dropped is removed');
        $this->assertSame('old', file_get_contents($result['rollback'].'/app/Old/Gone.php'), '… but kept in the rollback folder');
        $this->assertContains('app/Old/Gone.php', $result['removed']);
        $this->assertFileDoesNotExist($root.'/bootstrap/cache/packages.php');
        $this->assertFileDoesNotExist($root.'/bootstrap/cache/routes-v7.php');
        $this->assertSame(['app/Generated/File1.php'], array_slice(array_values(array_filter(
            CorePackage::signedFiles(file_get_contents($root.'/signature.json')), fn ($f) => str_starts_with($f, 'app/Generated/'))), 0, 1),
            'the new version file list is stored for the next update');
    }

    public function test_a_forged_file_list_never_deletes_anything(): void
    {
        $root = $this->site();
        file_put_contents($root.'/app/Precious.php', 'keep');
        $forged = json_encode(['alg' => 'Ed25519', 'signed' => base64_encode(json_encode(['product' => 'labeldesk', 'files' => ['app/Precious.php' => 'x']])), 'signature' => base64_encode(str_repeat('a', 64))]);
        file_put_contents($root.'/signature.json', $forged);

        $this->assertSame([], (new CoreUpdater($root))->obsoleteFiles(['app/Other.php']));
        $this->assertFileExists($root.'/app/Precious.php');
    }

    public function test_error_pages_render_without_the_translator(): void
    {
        $this->assertSame('Hello Ana', Text::t('Hello :name', ['name' => 'Ana']));
        $this->app->forgetInstance('translator');
        $this->app->offsetUnset('translator');
        $this->assertSame('Something went wrong', Text::t('Something went wrong'));
        $this->assertStringContainsString('Something went wrong', view('errors.500')->render());
    }

    /** @param  array<string, string>  $files */
    protected function stage(string $version, array $files): string
    {
        $stage = $this->work.'/stage-'.$version.'-'.bin2hex(random_bytes(3));
        foreach (range(1, 9) as $i) {
            $files["app/Generated/File{$i}.php"] = "<?php // {$i}";
        }
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($stage.'/'.$path));
            file_put_contents($stage.'/'.$path, $content);
        }
        CorePackage::sign($stage, $version, $this->secret);

        return $stage;
    }

    protected function zip(string $stage, string $version): string
    {
        $path = $this->work.'/labeldesk-'.$version.'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $zip->addFile($file->getPathname(), 'labeldesk-'.$version.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1)));
        }
        $zip->close();

        return $path;
    }
}

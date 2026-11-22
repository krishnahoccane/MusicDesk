<?php

namespace Tests\Concerns;

use App\Models\Plugin;
use App\Plugins\PluginManager;
use App\Plugins\PluginPackage;
use Illuminate\Support\Facades\File;

/**
 * Install and activate an add-on from its source folder in tests: signs it
 * with a throw-away key (trusted for this test only) and runs the real
 * upload → activate path with a development add-on key (MD-LD-TEST-ADDN-…,
 * accepted for any add-on by the mock license server in tests).
 */
trait InstallsAddons
{
    protected ?string $addonWorkDir = null;

    protected function installAddon(string $source, bool $activate = true): Plugin
    {
        if ($this->addonWorkDir === null) {
            $this->addonWorkDir = sys_get_temp_dir().'/ld-addon-test-'.bin2hex(random_bytes(4));
            File::ensureDirectoryExists($this->addonWorkDir.'/installed');
            $pair = sodium_crypto_sign_keypair();
            config([
                'licensing.testing.plugin_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'labeldesk.plugins_path' => $this->addonWorkDir.'/installed',
                'licensing.testing.plugin_secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            ]);
            $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->addonWorkDir));
        }

        $zip = $this->addonWorkDir.'/'.basename($source).'-'.bin2hex(random_bytes(3)).'.zip';
        PluginPackage::build($source, (string) config('licensing.testing.plugin_secret_key'), $zip);

        $manager = app(PluginManager::class);
        $plugin = $manager->install($zip);
        if ($activate) {
            $manager->activate($plugin, 'MD-LD-TEST-ADDN-AAAA-BBBB');
        }

        return $plugin->fresh();
    }
}

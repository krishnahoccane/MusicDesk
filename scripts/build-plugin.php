<?php

/**
 * Build a signed LabelDesk add-on ZIP.
 *
 *   php scripts/build-plugin.php addons/bulk-upload --key=<path to plugin-signing.key> [--out=build/plugins]
 *
 * The key file holds the base64 Ed25519 secret key whose public half is
 * LicenseAuthority::PLUGIN_PUBLIC_KEY. Keep it on the MusicDesk.tech side;
 * it must never be committed or shipped. Output: <out>/<slug>-<version>.zip
 */

use App\Plugins\PluginException;
use App\Plugins\PluginPackage;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$source = null;
$key = getenv('LABELDESK_PLUGIN_KEY') ?: null;
$out = $root.'/build/plugins';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--key=')) {
        $key = substr($arg, 6);
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } else {
        $source = $arg;
    }
}

if (! $source || ! is_dir($source) || ! $key || ! is_file($key)) {
    fwrite(STDERR, "Usage: php scripts/build-plugin.php <addon folder> --key=<plugin-signing.key> [--out=build/plugins]\n");
    exit(1);
}

try {
    $tmp = rtrim($out, '/\\').'/.building.zip';
    $manifest = PluginPackage::build($source, (string) file_get_contents($key), $tmp);
    $target = rtrim($out, '/\\').'/'.$manifest->slug().'-'.$manifest->version().'.zip';
    @unlink($target);
    rename($tmp, $target);

    // Prove the result installs: open and verify it like LabelDesk does.
    PluginPackage::open($target)->close();
} catch (PluginException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

echo 'Built '.$target.' ('.number_format(filesize($target) / 1024, 1)." KB)\n";

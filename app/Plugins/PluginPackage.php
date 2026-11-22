<?php

namespace App\Plugins;

use App\Services\License\LicenseAuthority;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * An add-on ZIP as downloaded from MusicDesk.tech:
 *
 *   <slug>/plugin.json
 *   <slug>/signature.json      Ed25519 signature by MusicDesk.tech
 *   <slug>/src/…, routes/…, resources/views/…, database/migrations/…
 *
 * signature.json = {"alg":"Ed25519","kid":"…","signed":"<base64 JSON>","signature":"<base64>"}
 * where the signed JSON is {"slug","version","addon","files":{"<path>":"<sha256>"}} and lists
 * every file in the package. Nothing is extracted unless the signature and every hash match,
 * so only code published by MusicDesk.tech can be installed.
 */
final class PluginPackage
{
    public const MAX_FILES = 5000;

    public const MAX_BYTES = 200 * 1024 * 1024;

    /** @var array<string, string> path inside the plugin folder => sha256 */
    private array $files = [];

    private function __construct(private ZipArchive $zip, public readonly PluginManifest $manifest, private string $root) {}

    public static function open(string $path): self
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new PluginException(__('The file is not a valid ZIP archive.'));
        }

        if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_FILES) {
            throw new PluginException(__('The add-on package has an unexpected number of files.'));
        }

        // Every entry must sit inside one top-level folder named after the slug.
        $root = null;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            self::assertSafeName($name);
            $top = explode('/', $name, 2)[0];
            $root ??= $top;
            if ($top !== $root) {
                throw new PluginException(__('The add-on package must contain a single folder.'));
            }
            // Symbolic links are refused (Unix mode in the external attributes).
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new PluginException(__('The add-on package contains a link, which is not allowed.'));
            }
            $total += (int) $stat['size'];
        }
        if ($total > self::MAX_BYTES) {
            throw new PluginException(__('The add-on package is too large.'));
        }

        $json = $zip->getFromName($root.'/plugin.json');
        if ($json === false) {
            throw new PluginException(__('This is not a LabelDesk add-on: plugin.json is missing.'));
        }
        $manifest = PluginManifest::fromJson($json);
        if ($manifest->slug() !== $root) {
            throw new PluginException(__('The folder name in the package does not match the add-on.'));
        }

        $package = new self($zip, $manifest, $root);
        $package->verify();

        return $package;
    }

    private static function assertSafeName(string $name): void
    {
        if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)
            || preg_match('/[\x00-\x1F:]/', $name) || strlen($name) > 400) {
            throw new PluginException(__('The add-on package contains an unsafe file name.'));
        }
    }

    private function verify(): void
    {
        $raw = $this->zip->getFromName($this->root.'/signature.json');
        $signature = $raw === false ? null : json_decode($raw, true);
        if (! is_array($signature) || ($signature['alg'] ?? null) !== 'Ed25519' || ! is_string($signature['signed'] ?? null) || ! is_string($signature['signature'] ?? null)) {
            throw new PluginException(__('The add-on is not signed by MusicDesk.tech. Download it again from your MusicDesk.tech account.'));
        }

        $key = base64_decode(LicenseAuthority::pluginPublicKey(), true);
        $signed = base64_decode($signature['signed'], true);
        $sig = base64_decode($signature['signature'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signed === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, $signed, $key)) {
            throw new PluginException(__('The add-on signature is not valid. The file may have been changed; download it again from your MusicDesk.tech account.'));
        }

        $claims = json_decode($signed, true);
        if (! is_array($claims) || ($claims['slug'] ?? null) !== $this->manifest->slug() || ($claims['version'] ?? null) !== $this->manifest->version()
            || ($claims['addon'] ?? null) !== $this->manifest->addon() || ! is_array($claims['files'] ?? null)) {
            throw new PluginException(__('The add-on signature does not match its plugin.json.'));
        }

        $expected = $claims['files'];
        $seen = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen($this->root) + 1);
            if ($relative === 'signature.json') {
                continue;
            }
            if (! isset($expected[$relative])) {
                throw new PluginException(__('The add-on contains a file that MusicDesk.tech did not sign: :file', ['file' => $relative]));
            }
            $stream = $this->zip->getStream($name);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            if (! hash_equals((string) $expected[$relative], hash_final($ctx))) {
                throw new PluginException(__('The add-on file :file was changed after signing.', ['file' => $relative]));
            }
            $seen[$relative] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new PluginException(__('The add-on package is incomplete.'));
        }

        $this->files = $expected;
    }

    /**
     * Extract into $directory (replaced if it exists).
     */
    public function extractTo(string $directory): void
    {
        $staging = $directory.'.incoming-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($staging);

        try {
            foreach (array_keys($this->files) as $relative) {
                $target = $staging.'/'.$relative;
                File::ensureDirectoryExists(dirname($target));
                $in = $this->zip->getStream($this->root.'/'.$relative);
                $out = fopen($target, 'wb');
                if (! $in || ! $out) {
                    throw new PluginException(__('Could not write the add-on files. Check that the plugins folder is writable.'));
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
            $sig = $this->zip->getFromName($this->root.'/signature.json');
            file_put_contents($staging.'/signature.json', (string) $sig);

            if (File::isDirectory($directory)) {
                $old = $directory.'.old-'.bin2hex(random_bytes(4));
                File::moveDirectory($directory, $old);
                File::moveDirectory($staging, $directory);
                File::deleteDirectory($old);
            } else {
                File::moveDirectory($staging, $directory);
            }
        } finally {
            if (File::isDirectory($staging)) {
                File::deleteDirectory($staging);
            }
        }
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * Build a signed package from a plugin source folder (used by scripts/build-plugin.php
     * and tests; the secret key never ships with LabelDesk).
     */
    public static function build(string $sourceDir, string $secretKeyBase64, string $outZip): PluginManifest
    {
        $sourceDir = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/');
        $manifest = PluginManifest::fromJson((string) @file_get_contents($sourceDir.'/plugin.json'));
        $secret = base64_decode(trim($secretKeyBase64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new PluginException('The signing key is not a base64 Ed25519 secret key.');
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($sourceDir)), '/');
            if ($relative === 'signature.json' || preg_match('#(^|/)(\.git|node_modules|tests)(/|$)|(^|/)\.DS_Store$#', $relative)) {
                continue;
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        $signed = json_encode(['slug' => $manifest->slug(), 'version' => $manifest->version(), 'addon' => $manifest->addon(), 'files' => $files], JSON_UNESCAPED_SLASHES);
        $signature = json_encode([
            'alg' => 'Ed25519',
            'kid' => substr(hash('sha256', sodium_crypto_sign_publickey_from_secretkey($secret)), 0, 16),
            'signed' => base64_encode($signed),
            'signature' => base64_encode(sodium_crypto_sign_detached($signed, $secret)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        File::ensureDirectoryExists(dirname($outZip));
        @unlink($outZip);
        $zip = new ZipArchive;
        if ($zip->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PluginException('Cannot create '.$outZip);
        }
        $root = $manifest->slug();
        $zip->addEmptyDir($root);
        foreach (array_keys($files) as $relative) {
            $zip->addFile($sourceDir.'/'.$relative, $root.'/'.$relative);
        }
        $zip->addFromString($root.'/signature.json', (string) $signature);
        $zip->close();

        return $manifest;
    }
}

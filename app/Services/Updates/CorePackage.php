<?php

namespace App\Services\Updates;

use App\Plugins\PluginException;
use App\Plugins\PluginPackage;
use App\Services\License\LicenseAuthority;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * A LabelDesk release ZIP as built by scripts/package-release.php --key=…
 * (or signed by MusicDesk.tech when the release is published):
 *
 *   labeldesk-<version>/signature.json   Ed25519 signature by MusicDesk.tech
 *   labeldesk-<version>/app/…, vendor/…, public/…
 *
 * signature.json = {"alg":"Ed25519","kid":"…","signed":"<base64 JSON>","signature":"<base64>"} where the
 * signed JSON is {"product":"labeldesk","version":"…","files":{"<path>":"<sha256>"}} and lists every file.
 * Same signing key as add-on packages. Nothing is extracted unless every hash matches.
 */
final class CorePackage
{
    public const MAX_FILES = 80000;

    public const MAX_BYTES = 600 * 1024 * 1024;

    /** @var array<string, string> */
    private array $files = [];

    private function __construct(private ZipArchive $zip, private string $root, public readonly string $version) {}

    public static function open(string $path, ?string $expectedVersion = null): self
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('The update is not a valid ZIP archive.'));
        }
        if ($zip->numFiles < 10 || $zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException(__('The update package has an unexpected number of files.'));
        }

        $root = null;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            try {
                PluginPackage::assertSafeName($name);
            } catch (PluginException) {
                throw new RuntimeException(__('The update package contains an unsafe file name.'));
            }
            $top = explode('/', $name, 2)[0];
            $root ??= $top;
            if ($top !== $root) {
                throw new RuntimeException(__('The update package must contain a single folder.'));
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException(__('The update package contains a link, which is not allowed.'));
            }
            $total += (int) $stat['size'];
        }
        if ($total > self::MAX_BYTES) {
            throw new RuntimeException(__('The update package is too large.'));
        }
        if (! preg_match('/^labeldesk-(\d+\.\d+\.\d+[0-9A-Za-z.\-]*)$/', (string) $root, $m)) {
            throw new RuntimeException(__('This is not a LabelDesk update package.'));
        }

        $package = new self($zip, (string) $root, $m[1]);
        if ($expectedVersion !== null && $package->version !== $expectedVersion) {
            throw new RuntimeException(__('The package is version :got, expected :want.', ['got' => $package->version, 'want' => $expectedVersion]));
        }
        $package->verify();

        return $package;
    }

    private function verify(): void
    {
        $raw = $this->zip->getFromName($this->root.'/signature.json');
        $signature = $raw === false ? null : json_decode($raw, true);
        if (! is_array($signature) || ($signature['alg'] ?? null) !== 'Ed25519' || ! is_string($signature['signed'] ?? null) || ! is_string($signature['signature'] ?? null)) {
            throw new RuntimeException(__('The update is not signed by MusicDesk.tech.'));
        }

        $key = base64_decode(LicenseAuthority::pluginPublicKey(), true);
        $signed = base64_decode($signature['signed'], true);
        $sig = base64_decode($signature['signature'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signed === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, $signed, $key)) {
            throw new RuntimeException(__('The update signature is not valid. The file may have been changed.'));
        }

        $claims = json_decode($signed, true);
        if (! is_array($claims) || ($claims['product'] ?? null) !== 'labeldesk' || ($claims['version'] ?? null) !== $this->version || ! is_array($claims['files'] ?? null)) {
            throw new RuntimeException(__('The update signature does not match the package.'));
        }

        $expected = $claims['files'];
        $seen = 0;
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
                throw new RuntimeException(__('The update contains a file that MusicDesk.tech did not sign: :file', ['file' => $relative]));
            }
            $stream = $this->zip->getStream($name);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            if (! hash_equals((string) $expected[$relative], hash_final($ctx))) {
                throw new RuntimeException(__('The update file :file was changed after signing.', ['file' => $relative]));
            }
            $seen++;
        }
        if ($seen !== count($expected)) {
            throw new RuntimeException(__('The update package is incomplete.'));
        }

        $this->files = $expected;
    }

    /** The package's signature.json (written to the LabelDesk folder after an update). */
    public function signatureJson(): string
    {
        return (string) $this->zip->getFromName($this->root.'/signature.json');
    }

    /**
     * The file list of an installed version, from its signature.json — only when the
     * MusicDesk.tech signature is valid, so nothing is ever deleted on a forged list.
     *
     * @return list<string>|null
     */
    public static function signedFiles(string $signatureJson): ?array
    {
        $signature = json_decode($signatureJson, true);
        if (! is_array($signature) || ! is_string($signature['signed'] ?? null) || ! is_string($signature['signature'] ?? null)) {
            return null;
        }
        $key = base64_decode(LicenseAuthority::pluginPublicKey(), true);
        $signed = base64_decode($signature['signed'], true);
        $sig = base64_decode($signature['signature'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signed === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, $signed, $key)) {
            return null;
        }
        $claims = json_decode($signed, true);

        return is_array($claims) && ($claims['product'] ?? null) === 'labeldesk' && is_array($claims['files'] ?? null)
            ? array_map('strval', array_keys($claims['files'])) : null;
    }

    /** @return list<string> relative paths of every signed file */
    public function files(): array
    {
        return array_keys($this->files);
    }

    /** Extract every signed file into $directory (created). */
    public function extractTo(string $directory): void
    {
        File::ensureDirectoryExists($directory);
        foreach (array_keys($this->files) as $relative) {
            $target = $directory.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));
            $in = $this->zip->getStream($this->root.'/'.$relative);
            $out = @fopen($target, 'wb');
            if (! $in || ! $out) {
                throw new RuntimeException(__('Could not unpack the update. Check that the storage folder is writable and has free space.'));
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * Sign a staged release folder in place (writes signature.json). Used by
     * scripts/package-release.php and tests; the secret key never ships with LabelDesk.
     */
    public static function sign(string $stageDir, string $version, string $secretKeyBase64): void
    {
        $secret = base64_decode(trim($secretKeyBase64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The signing key is not a base64 Ed25519 secret key.');
        }
        $stageDir = rtrim(str_replace('\\', '/', $stageDir), '/');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stageDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($stageDir)), '/');
            if ($relative !== 'signature.json') {
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        $signed = json_encode(['product' => 'labeldesk', 'version' => $version, 'files' => $files], JSON_UNESCAPED_SLASHES);
        file_put_contents($stageDir.'/signature.json', json_encode([
            'alg' => 'Ed25519',
            'kid' => substr(hash('sha256', sodium_crypto_sign_publickey_from_secretkey($secret)), 0, 16),
            'signed' => base64_encode((string) $signed),
            'signature' => base64_encode(sodium_crypto_sign_detached((string) $signed, $secret)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

<?php

namespace LabelDesk\Addons\MediaStorage\Drivers;

use FTP\Connection;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageException;

/**
 * FTP / FTPS (explicit TLS) server. Folders and files are addressed by path.
 * Uses PHP's ftp extension; no OAuth — "Connect" logs in to test the details.
 */
class Ftp extends CloudDrive
{
    public static function type(): string
    {
        return 'ftp';
    }

    public static function label(): string
    {
        return 'FTP server';
    }

    public static function fields(): array
    {
        return [
            'host' => ['label' => __('Host'), 'type' => 'text', 'required' => true, 'help' => __('e.g. ftp.yourlabel.com')],
            'port' => ['label' => __('Port'), 'type' => 'text', 'default' => '21'],
            'username' => ['label' => __('Username'), 'type' => 'text', 'required' => true],
            'password' => ['label' => __('Password'), 'type' => 'password', 'required' => true, 'secret' => true],
            'root' => ['label' => __('Start folder'), 'type' => 'text', 'default' => '/', 'help' => __('The folder picker starts here.')],
            'encryption' => ['label' => __('Encryption'), 'type' => 'select', 'default' => 'tls', 'options' => ['tls' => __('FTPS (explicit TLS) — recommended'), 'none' => __('None (plain FTP)')]],
            'passive' => ['label' => __('Passive mode'), 'type' => 'select', 'default' => 'yes', 'options' => ['yes' => __('Yes (works behind most firewalls)'), 'no' => __('No')]],
        ];
    }

    public static function usesOAuth(): bool
    {
        return false;
    }

    public static function pathBased(): bool
    {
        return true;
    }

    public function rootId(): string
    {
        $root = '/'.trim((string) $this->setting('root', '/'), '/');

        return $root === '/' ? '/' : $root;
    }

    public function rootName(): string
    {
        return (string) $this->setting('host', 'FTP');
    }

    public function testConnection(): string
    {
        $ftp = $this->connect();
        $ok = @ftp_chdir($ftp, $this->rootId());
        ftp_close($ftp);
        if (! $ok) {
            throw new MediaStorageException(__('Signed in, but the start folder ":folder" does not exist.', ['folder' => $this->rootId()]));
        }

        return $this->setting('username').'@'.$this->setting('host');
    }

    protected function connect(): Connection
    {
        if (! extension_loaded('ftp')) {
            throw new MediaStorageException(__('The PHP ftp extension is not enabled on this server.'));
        }

        $host = (string) $this->setting('host');
        $port = (int) $this->setting('port', 21) ?: 21;
        $ftp = $this->setting('encryption', 'tls') === 'tls'
            ? @ftp_ssl_connect($host, $port, 20)
            : @ftp_connect($host, $port, 20);

        if (! $ftp) {
            throw new MediaStorageException(__('Could not connect to :host:port.', ['host' => $host, 'port' => $port]));
        }
        if (! @ftp_login($ftp, (string) $this->setting('username'), (string) $this->setting('password'))) {
            ftp_close($ftp);
            throw new MediaStorageException(__('The FTP server refused the username or password.'));
        }
        ftp_pasv($ftp, $this->setting('passive', 'yes') !== 'no');

        return $ftp;
    }

    protected function join(string $folder, string $name): string
    {
        return rtrim($folder, '/').'/'.$name;
    }

    public function listFolders(string $parentId): array
    {
        $ftp = $this->connect();
        try {
            $entries = @ftp_mlsd($ftp, $parentId);
            $folders = [];
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (($entry['type'] ?? '') === 'dir') {
                        $folders[] = ['id' => $this->join($parentId, $entry['name']), 'name' => $entry['name']];
                    }
                }
            } else {
                // Servers without MLSD: try every entry as a directory.
                foreach ((array) @ftp_nlist($ftp, $parentId) as $path) {
                    $name = basename((string) $path);
                    if ($name !== '.' && $name !== '..' && @ftp_chdir($ftp, $this->join($parentId, $name))) {
                        $folders[] = ['id' => $this->join($parentId, $name), 'name' => $name];
                    }
                }
            }
        } finally {
            ftp_close($ftp);
        }

        usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $folders;
    }

    public function createFolder(string $name, string $parentId): string
    {
        $ftp = $this->connect();
        $path = $this->join($parentId, $name);
        $ok = @ftp_mkdir($ftp, $path);
        ftp_close($ftp);
        if ($ok === false) {
            throw new MediaStorageException(__('The folder :path could not be created.', ['path' => $path]));
        }

        return $path;
    }

    public function findOrCreateFolder(string $name, string $parentId): string
    {
        $ftp = $this->connect();
        $path = $this->join($parentId, $name);
        $exists = @ftp_chdir($ftp, $path);
        ftp_close($ftp);

        return $exists ? $path : $this->createFolder($name, $parentId);
    }

    public function renameFolder(string $id, string $name, string $parentId): string
    {
        $to = $this->join($parentId, $name);
        $ftp = $this->connect();
        $ok = @ftp_rename($ftp, $id, $to);
        ftp_close($ftp);
        if (! $ok) {
            throw new MediaStorageException(__('The folder could not be renamed.'));
        }

        return $to;
    }

    public function upload(string $localPath, string $name, string $folderId, string $mime): array
    {
        $ftp = $this->connect();
        $path = $this->join($folderId, $name);
        try {
            if (! @ftp_put($ftp, $path, $localPath, FTP_BINARY)) {
                throw new MediaStorageException(__('The FTP server refused the upload of :name.', ['name' => $name]));
            }
            $size = @ftp_size($ftp, $path);
        } finally {
            ftp_close($ftp);
        }

        return ['id' => $path, 'name' => $name, 'size' => (int) $size, 'md5' => null, 'sha1' => null];
    }

    public function update(string $id, ?string $name, ?string $folderId, ?string $oldFolderId): string
    {
        $to = $this->join($folderId ?? dirname($id), $name ?? basename($id));
        if ($to === $id) {
            return $id;
        }

        $ftp = $this->connect();
        $ok = @ftp_rename($ftp, $id, $to);
        ftp_close($ftp);
        if (! $ok) {
            throw new MediaStorageException(__('The file could not be renamed.'));
        }

        return $to;
    }

    public function metadata(string $id): ?array
    {
        $ftp = $this->connect();
        $size = @ftp_size($ftp, $id);
        ftp_close($ftp);

        return $size < 0 ? null : ['id' => $id, 'name' => basename($id), 'size' => (int) $size, 'md5' => null, 'sha1' => null];
    }

    public function trash(string $id): void
    {
        $ftp = $this->connect();
        @ftp_delete($ftp, $id);
        ftp_close($ftp);
    }

    /**
     * FTP has no byte ranges over this API: the whole file is fetched to a
     * temporary stream and served from there.
     */
    public function download(string $id, ?string $range = null): Response
    {
        $ftp = $this->connect();
        $temp = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
        $ok = @ftp_fget($ftp, $temp, $id, FTP_BINARY);
        ftp_close($ftp);
        if (! $ok) {
            throw new MediaStorageException(__('The file could not be downloaded from the FTP server.'));
        }
        $size = ftell($temp);
        rewind($temp);

        return new Response(new PsrResponse(200, ['Content-Length' => (string) $size], Utils::streamFor($temp)));
    }

    // FTP has no OAuth.
    public function authorizeUrl(string $redirectUri, string $state): string
    {
        throw new MediaStorageException(__('FTP servers do not use sign-in links.'));
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        throw new MediaStorageException(__('FTP servers do not use sign-in links.'));
    }

    protected function tokenUrl(): string
    {
        return '';
    }
}

<?php

namespace App\Services\MediaStorage\Drivers;

use Illuminate\Http\Client\Response;

/**
 * Dropbox API v2 (https://www.dropbox.com/developers/documentation/http/documentation).
 * Folders are addressed by path, files by their stable "id:…". Uploads use
 * upload sessions; copies are verified with Dropbox's content hash.
 */
class Dropbox extends CloudDrive
{
    protected const API = 'https://api.dropboxapi.com/2';

    protected const CONTENT = 'https://content.dropboxapi.com/2';

    public static function type(): string
    {
        return 'dropbox';
    }

    public static function label(): string
    {
        return 'Dropbox';
    }

    public static function fields(): array
    {
        return [
            'client_id' => ['label' => __('App key'), 'type' => 'text', 'required' => true],
            'client_secret' => ['label' => __('App secret'), 'type' => 'password', 'required' => true, 'secret' => true],
        ];
    }

    public static function pathBased(): bool
    {
        return true; // folders
    }

    public function rootId(): string
    {
        return '';
    }

    public function rootName(): string
    {
        return 'Dropbox';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id' => (string) $this->setting('client_id'),
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->asForm()->acceptJson()->timeout(30)->post($this->tokenUrl(), [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'client_id' => (string) $this->setting('client_id'),
            'client_secret' => (string) $this->setting('client_secret'),
        ]);

        if (! $response->successful() || ! is_string($response->json('refresh_token'))) {
            $this->fail($response, __('connecting'));
        }

        $me = $this->http->withToken((string) $response->json('access_token'))->acceptJson()->timeout(30)
            ->withBody('null', 'application/json')->post(self::API.'/users/get_current_account');

        return [
            'refresh_token' => (string) $response->json('refresh_token'),
            'account' => (string) ($me->json('email') ?? $me->json('name.display_name') ?? 'Dropbox account'),
        ];
    }

    protected function tokenUrl(): string
    {
        return 'https://api.dropboxapi.com/oauth2/token';
    }

    protected function rpc(string $endpoint, array $body): Response
    {
        return $this->api()->post(self::API.$endpoint, $body);
    }

    public function listFolders(string $parentId): array
    {
        $folders = [];
        $response = $this->rpc('/files/list_folder', ['path' => $parentId, 'limit' => 2000]);

        while (true) {
            $response->successful() || $this->fail($response, __('listing folders'));
            foreach ((array) $response->json('entries', []) as $entry) {
                if (($entry['.tag'] ?? null) === 'folder') {
                    $folders[] = ['id' => (string) $entry['path_display'], 'name' => (string) $entry['name']];
                }
            }
            if (! $response->json('has_more') || count($folders) >= 2000) {
                break;
            }
            $response = $this->rpc('/files/list_folder/continue', ['cursor' => $response->json('cursor')]);
        }

        usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $folders;
    }

    public function createFolder(string $name, string $parentId): string
    {
        $response = $this->rpc('/files/create_folder_v2', ['path' => rtrim($parentId, '/').'/'.$name, 'autorename' => false]);
        $response->successful() || $this->fail($response, __('creating a folder'));

        return (string) $response->json('metadata.path_display');
    }

    public function findOrCreateFolder(string $name, string $parentId): string
    {
        $path = rtrim($parentId, '/').'/'.$name;
        $existing = $this->rpc('/files/get_metadata', ['path' => $path]);
        if ($existing->successful() && $existing->json('.tag') === 'folder') {
            return (string) $existing->json('path_display');
        }

        return $this->createFolder($name, $parentId);
    }

    public function renameFolder(string $id, string $name, string $parentId): string
    {
        $response = $this->rpc('/files/move_v2', ['from_path' => $id, 'to_path' => rtrim($parentId, '/').'/'.$name, 'autorename' => false]);
        $response->successful() || $this->fail($response, __('renaming'));

        return (string) $response->json('metadata.path_display');
    }

    public function upload(string $localPath, string $name, string $folderId, string $mime): array
    {
        $size = (int) filesize($localPath);
        $session = null;
        $offset = 0;

        foreach ($this->chunks($localPath, 8 * 1024 * 1024) as [$offset, $bytes]) {
            $arg = $session === null ? ['close' => false] : ['cursor' => ['session_id' => $session, 'offset' => $offset], 'close' => false];
            $response = $this->api()->withHeaders(['Dropbox-API-Arg' => json_encode($arg)])->withBody($bytes, 'application/octet-stream')
                ->timeout(300)->post(self::CONTENT.($session === null ? '/files/upload_session/start' : '/files/upload_session/append_v2'));
            $response->successful() || $this->fail($response, __('uploading'));
            $session ??= (string) $response->json('session_id');
            $offset += strlen($bytes);
        }

        if ($session === null) {
            $start = $this->api()->withHeaders(['Dropbox-API-Arg' => json_encode(['close' => false])])->withBody('', 'application/octet-stream')->post(self::CONTENT.'/files/upload_session/start');
            $start->successful() || $this->fail($start, __('uploading'));
            $session = (string) $start->json('session_id');
        }

        $finish = $this->api()->withHeaders(['Dropbox-API-Arg' => json_encode([
            'cursor' => ['session_id' => $session, 'offset' => $size],
            'commit' => ['path' => rtrim($folderId, '/').'/'.$name, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true],
        ])])->withBody('', 'application/octet-stream')->timeout(300)->post(self::CONTENT.'/files/upload_session/finish');
        $finish->successful() || $this->fail($finish, __('finishing the upload'));

        return $this->normalize((array) $finish->json());
    }

    public function update(string $id, ?string $name, ?string $folderId, ?string $oldFolderId): string
    {
        $current = $this->rpc('/files/get_metadata', ['path' => $id]);
        $current->successful() || $this->fail($current, __('renaming'));
        $parent = $folderId ?? dirname((string) $current->json('path_display'));
        $target = rtrim($parent, '/').'/'.($name ?? (string) $current->json('name'));

        if (strcasecmp($target, (string) $current->json('path_display')) !== 0 || $name !== (string) $current->json('name')) {
            $response = $this->rpc('/files/move_v2', ['from_path' => $id, 'to_path' => $target, 'autorename' => false]);
            $response->successful() || $this->fail($response, __('renaming'));
        }

        return $id; // file ids ("id:…") survive moves
    }

    public function metadata(string $id): ?array
    {
        $response = $this->rpc('/files/get_metadata', ['path' => $id]);
        if ($response->status() === 409) {
            return null; // path/not_found
        }
        $response->successful() || $this->fail($response, __('checking a file'));

        return $response->json('.tag') === 'file' ? $this->normalize((array) $response->json()) : null;
    }

    public function trash(string $id): void
    {
        $response = $this->rpc('/files/delete_v2', ['path' => $id]);
        if (! $response->successful() && $response->status() !== 409) {
            $this->fail($response, __('removing the old copy'));
        }
    }

    public function download(string $id, ?string $range = null): Response
    {
        return $this->http->withToken($this->accessToken())->withOptions(['stream' => true])->timeout(0)
            ->withHeaders(array_filter(['Dropbox-API-Arg' => json_encode(['path' => $id]), 'Range' => $range]))
            ->withBody('', 'text/plain')->post(self::CONTENT.'/files/download');
    }

    public function verify(string $localPath, array $remote): bool
    {
        return $remote['size'] === (int) filesize($localPath)
            && ($remote['hash'] ?? null) !== null && $remote['hash'] === self::contentHash($localPath);
    }

    /**
     * Dropbox content hash: SHA-256 over the concatenated SHA-256 digests of 4 MiB blocks.
     */
    public static function contentHash(string $path): string
    {
        $handle = fopen($path, 'rb');
        $digests = '';
        while ($handle && ! feof($handle)) {
            $block = fread($handle, 4 * 1024 * 1024);
            if ($block === '' || $block === false) {
                break;
            }
            $digests .= hash('sha256', $block, true);
        }
        $handle && fclose($handle);

        return hash('sha256', $digests);
    }

    protected function normalize(array $file): array
    {
        return [
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['name'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'md5' => null,
            'sha1' => null,
            'hash' => isset($file['content_hash']) ? strtolower((string) $file['content_hash']) : null,
        ];
    }
}

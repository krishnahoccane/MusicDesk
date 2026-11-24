<?php

namespace LabelDesk\Addons\MediaStorage\Drivers;

use Illuminate\Http\Client\Response;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageException;

/**
 * OneDrive / OneDrive for Business through Microsoft Graph v1.0
 * (https://learn.microsoft.com/graph/api/resources/onedrive).
 * Tenant "common" accepts personal and work accounts; a tenant id limits it
 * to one organisation.
 */
class OneDrive extends CloudDrive
{
    protected const GRAPH = 'https://graph.microsoft.com/v1.0';

    protected const SCOPES = 'offline_access Files.ReadWrite.All User.Read';

    public static function type(): string
    {
        return 'onedrive';
    }

    public static function label(): string
    {
        return 'OneDrive';
    }

    public static function fields(): array
    {
        return [
            'client_id' => ['label' => __('Application (client) ID'), 'type' => 'text', 'required' => true],
            'client_secret' => ['label' => __('Client secret'), 'type' => 'password', 'required' => true, 'secret' => true],
            'tenant' => ['label' => __('Tenant'), 'type' => 'text', 'default' => 'common', 'help' => __('"common" for personal and work accounts, "consumers" for personal only.')],
        ];
    }

    protected function defaultTenant(): string
    {
        return 'common';
    }

    public function rootId(): string
    {
        return 'root';
    }

    protected function tenant(): string
    {
        $tenant = (string) $this->setting('tenant', $this->defaultTenant());

        return preg_match('/^[A-Za-z0-9.\-]{1,100}$/', $tenant) ? $tenant : $this->defaultTenant();
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant().'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => (string) $this->setting('client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->asForm()->acceptJson()->timeout(30)->post($this->tokenUrl(), [
            'client_id' => (string) $this->setting('client_id'),
            'client_secret' => (string) $this->setting('client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => self::SCOPES,
        ]);

        if (! $response->successful() || ! is_string($response->json('refresh_token'))) {
            $this->fail($response, __('connecting'));
        }

        $me = $this->http->withToken((string) $response->json('access_token'))->acceptJson()->timeout(30)
            ->get(self::GRAPH.'/me', ['$select' => 'mail,userPrincipalName,displayName']);

        return [
            'refresh_token' => (string) $response->json('refresh_token'),
            'account' => (string) ($me->json('mail') ?? $me->json('userPrincipalName') ?? $me->json('displayName') ?? 'Microsoft account'),
        ];
    }

    protected function tokenUrl(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant().'/oauth2/v2.0/token';
    }

    protected function refreshParameters(): array
    {
        return ['scope' => self::SCOPES];
    }

    protected function item(string $id): string
    {
        return $id === 'root' ? self::GRAPH.'/me/drive/root' : self::GRAPH.'/me/drive/items/'.rawurlencode($id);
    }

    public function listFolders(string $parentId): array
    {
        $folders = [];
        $url = $this->item($parentId).'/children?'.http_build_query(['$select' => 'id,name,folder', '$top' => 200]);

        do {
            $response = $this->api()->get($url);
            $response->successful() || $this->fail($response, __('listing folders'));

            foreach ((array) $response->json('value', []) as $item) {
                if (isset($item['folder'])) {
                    $folders[] = ['id' => (string) $item['id'], 'name' => (string) $item['name']];
                }
            }
            $url = $response->json('@odata.nextLink');
        } while (is_string($url) && count($folders) < 2000);

        usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $folders;
    }

    public function createFolder(string $name, string $parentId): string
    {
        $response = $this->api()->post($this->item($parentId).'/children', [
            'name' => $name,
            'folder' => new \stdClass,
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]);
        $response->successful() || $this->fail($response, __('creating a folder'));

        return (string) $response->json('id');
    }

    public function findOrCreateFolder(string $name, string $parentId): string
    {
        $existing = $this->api()->get($this->item($parentId).':/'.rawurlencode($name).':', ['$select' => 'id,folder']);
        if ($existing->successful() && $existing->json('folder') !== null) {
            return (string) $existing->json('id');
        }
        if ($existing->status() !== 404 && ! $existing->successful()) {
            $this->fail($existing, __('finding a folder'));
        }

        return $this->createFolder($name, $parentId);
    }

    /**
     * Upload session in CHUNK-sized pieces (multiples of 320 KiB, as Graph requires).
     */
    public function upload(string $localPath, string $name, string $folderId, string $mime): array
    {
        $size = (int) filesize($localPath);

        $session = $this->api()->post($this->item($folderId).':/'.rawurlencode($name).':/createUploadSession', [
            'item' => ['@microsoft.graph.conflictBehavior' => 'replace'],
        ]);
        $session->successful() || $this->fail($session, __('starting the upload'));
        $uploadUrl = (string) $session->json('uploadUrl');

        $last = null;
        foreach ($this->chunks($localPath) as [$offset, $bytes]) {
            $end = $offset + strlen($bytes) - 1;
            // The upload URL is pre-authorised: no Authorization header.
            $last = $this->http
                ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                ->withBody($bytes, 'application/octet-stream')
                ->timeout(300)
                ->put($uploadUrl);

            if (! in_array($last->status(), [200, 201, 202], true)) {
                $this->fail($last, __('uploading'));
            }
        }

        if (! $last || ! in_array($last->status(), [200, 201], true)) {
            throw new MediaStorageException(__('OneDrive did not confirm the upload.'));
        }

        return $this->normalize((array) $last->json());
    }

    public function update(string $id, ?string $name, ?string $folderId, ?string $oldFolderId): string
    {
        $body = array_filter([
            'name' => $name,
            'parentReference' => $folderId && $folderId !== $oldFolderId ? ['id' => $folderId] : null,
        ]);
        if ($body === []) {
            return $id;
        }

        $response = $this->api()->patch($this->item($id), $body);
        $response->successful() || $this->fail($response, __('renaming'));

        return $id;
    }

    public function metadata(string $id): ?array
    {
        $response = $this->api()->get($this->item($id), ['$select' => 'id,name,size,file,deleted']);
        if ($response->status() === 404) {
            return null;
        }
        $response->successful() || $this->fail($response, __('checking a file'));

        return $response->json('deleted') ? null : $this->normalize((array) $response->json());
    }

    public function trash(string $id): void
    {
        $response = $this->api()->delete($this->item($id));
        if (! $response->successful() && $response->status() !== 404) {
            $this->fail($response, __('removing the old copy'));
        }
    }

    public function download(string $id, ?string $range = null): Response
    {
        $meta = $this->api()->get($this->item($id), ['$select' => 'id,@microsoft.graph.downloadUrl']);
        $meta->successful() || $this->fail($meta, __('downloading'));
        $url = (string) $meta->json('@microsoft.graph.downloadUrl');

        // Pre-authenticated short-lived URL: no Authorization header.
        return $this->http->withOptions(['stream' => true])->timeout(0)->withHeaders(array_filter(['Range' => $range]))->get($url);
    }

    /**
     * @return array{id: string, name: string, size: int, md5: ?string, sha1: ?string}
     */
    protected function normalize(array $item): array
    {
        return [
            'id' => (string) ($item['id'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'size' => (int) ($item['size'] ?? 0),
            'md5' => null,
            'sha1' => isset($item['file']['hashes']['sha1Hash']) ? strtolower((string) $item['file']['hashes']['sha1Hash']) : null,
        ];
    }
}

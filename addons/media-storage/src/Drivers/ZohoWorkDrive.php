<?php

namespace LabelDesk\Addons\MediaStorage\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageException;

/**
 * Zoho WorkDrive API v1 (https://workdrive.zoho.com/apidocs/v1). Zoho runs
 * separate data centres (zoho.com, .eu, .in, .com.au, .jp, .ca, .sa), chosen
 * per destination. The folder picker starts at "My Folders" and the team
 * folders the account can open.
 */
class ZohoWorkDrive extends CloudDrive
{
    public const DATA_CENTRES = ['com' => 'zoho.com (US)', 'eu' => 'zoho.eu (Europe)', 'in' => 'zoho.in (India)', 'com.au' => 'zoho.com.au (Australia)', 'jp' => 'zoho.jp (Japan)', 'ca' => 'zohocloud.ca (Canada)', 'sa' => 'zoho.sa (Saudi Arabia)'];

    protected const SCOPES = 'WorkDrive.files.ALL,WorkDrive.team.READ,WorkDrive.teamfolders.READ,WorkDrive.workspace.READ,ZohoFiles.files.ALL';

    public static function type(): string
    {
        return 'zoho_workdrive';
    }

    public static function label(): string
    {
        return 'Zoho WorkDrive';
    }

    public static function fields(): array
    {
        return [
            'client_id' => ['label' => __('Client ID'), 'type' => 'text', 'required' => true],
            'client_secret' => ['label' => __('Client secret'), 'type' => 'password', 'required' => true, 'secret' => true],
            'dc' => ['label' => __('Data centre'), 'type' => 'select', 'required' => true, 'default' => 'com', 'options' => self::DATA_CENTRES],
        ];
    }

    protected function dc(): string
    {
        $dc = (string) $this->setting('dc', 'com');

        return array_key_exists($dc, self::DATA_CENTRES) ? $dc : 'com';
    }

    protected function accounts(): string
    {
        return $this->dc() === 'ca' ? 'https://accounts.zohocloud.ca' : 'https://accounts.zoho.'.$this->dc();
    }

    protected function apiBase(): string
    {
        return ($this->dc() === 'ca' ? 'https://www.zohoapis.ca' : 'https://www.zohoapis.'.$this->dc()).'/workdrive/api/v1';
    }

    protected function downloadBase(): string
    {
        return ($this->dc() === 'ca' ? 'https://download.zohocloud.ca' : 'https://download.zoho.'.$this->dc()).'/v1/workdrive/download/';
    }

    public function rootId(): string
    {
        return 'root';
    }

    public function rootName(): string
    {
        return 'WorkDrive';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return $this->accounts().'/oauth/v2/auth?'.http_build_query([
            'scope' => self::SCOPES,
            'client_id' => (string) $this->setting('client_id'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->asForm()->acceptJson()->timeout(30)->post($this->tokenUrl(), [
            'code' => $code,
            'client_id' => (string) $this->setting('client_id'),
            'client_secret' => (string) $this->setting('client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('refresh_token'))) {
            $this->fail($response, __('connecting'));
        }

        $me = $this->http->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$response->json('access_token'), 'Accept' => 'application/vnd.api+json'])
            ->timeout(30)->get($this->apiBase().'/users/me');

        return [
            'refresh_token' => (string) $response->json('refresh_token'),
            'account' => (string) ($me->json('data.attributes.email_id') ?? $me->json('data.attributes.display_name') ?? 'Zoho account'),
        ];
    }

    protected function tokenUrl(): string
    {
        return $this->accounts().'/oauth/v2/token';
    }

    protected function api(): PendingRequest
    {
        return $this->http->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$this->accessToken(), 'Accept' => 'application/vnd.api+json'])
            ->timeout(60)->connectTimeout(15);
    }

    /**
     * Root level: the user's "My Folders" and the team folders they belong to.
     */
    public function listFolders(string $parentId): array
    {
        if ($parentId === 'root') {
            $me = $this->api()->get($this->apiBase().'/users/me');
            $me->successful() || $this->fail($me, __('listing folders'));
            $userId = (string) $me->json('data.id');
            $folders = [];

            $private = $this->api()->get($this->apiBase().'/users/'.rawurlencode($userId).'/privatespace');
            foreach ((array) $private->json('data', []) as $space) {
                $folders[] = ['id' => (string) $space['id'], 'name' => __('My Folders')];
            }

            $teamId = (string) ($me->json('data.attributes.preferred_team_id') ?? '');
            if ($teamId !== '') {
                $teamFolders = $this->api()->get($this->apiBase().'/teams/'.rawurlencode($teamId).'/workspaces');
                foreach ((array) $teamFolders->json('data', []) as $folder) {
                    $folders[] = ['id' => (string) $folder['id'], 'name' => (string) ($folder['attributes']['name'] ?? $folder['id'])];
                }
            }

            return $folders;
        }

        $folders = [];
        $offset = 0;
        do {
            $response = $this->api()->get($this->apiBase().'/files/'.rawurlencode($parentId).'/files', [
                'filter[type]' => 'folder', 'page[limit]' => 50, 'page[offset]' => $offset,
            ]);
            $response->successful() || $this->fail($response, __('listing folders'));
            $page = (array) $response->json('data', []);
            foreach ($page as $item) {
                $folders[] = ['id' => (string) $item['id'], 'name' => (string) ($item['attributes']['name'] ?? $item['id'])];
            }
            $offset += 50;
        } while (count($page) === 50 && $offset < 2000);

        return $folders;
    }

    public function createFolder(string $name, string $parentId): string
    {
        $response = $this->api()->withBody(json_encode(['data' => ['attributes' => ['name' => $name, 'parent_id' => $parentId], 'type' => 'files']]), 'application/vnd.api+json')
            ->post($this->apiBase().'/files');
        $response->successful() || $this->fail($response, __('creating a folder'));

        return (string) $response->json('data.id');
    }

    public function findOrCreateFolder(string $name, string $parentId): string
    {
        foreach ($this->listFolders($parentId) as $folder) {
            if ($folder['name'] === $name) {
                return $folder['id'];
            }
        }

        return $this->createFolder($name, $parentId);
    }

    public function upload(string $localPath, string $name, string $folderId, string $mime): array
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new MediaStorageException(__('The local file could not be read.'));
        }

        try {
            // Streamed multipart upload (the file is not loaded into memory).
            $response = $this->api()->timeout(0)->attach('content', $handle, $name, ['Content-Type' => $mime])
                ->post($this->apiBase().'/upload', ['filename' => $name, 'parent_id' => $folderId, 'override-name-exist' => 'true']);
        } finally {
            is_resource($handle) && fclose($handle);
        }
        $response->successful() || $this->fail($response, __('uploading'));

        $id = (string) ($response->json('data.0.attributes.resource_id') ?? '');
        if ($id === '') {
            throw new MediaStorageException(__('Zoho WorkDrive did not confirm the upload.'));
        }

        return $this->metadata($id) ?? throw new MediaStorageException(__('Zoho WorkDrive did not confirm the upload.'));
    }

    public function update(string $id, ?string $name, ?string $folderId, ?string $oldFolderId): string
    {
        $attributes = array_filter(['name' => $name, 'parent_id' => $folderId && $folderId !== $oldFolderId ? $folderId : null]);
        if ($attributes === []) {
            return $id;
        }

        $response = $this->api()->withBody(json_encode(['data' => ['attributes' => $attributes, 'type' => 'files']]), 'application/vnd.api+json')
            ->patch($this->apiBase().'/files/'.rawurlencode($id));
        $response->successful() || $this->fail($response, __('renaming'));

        return $id;
    }

    public function metadata(string $id): ?array
    {
        $response = $this->api()->get($this->apiBase().'/files/'.rawurlencode($id));
        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }
        $response->successful() || $this->fail($response, __('checking a file'));

        $attributes = (array) $response->json('data.attributes', []);
        if ((string) ($attributes['status'] ?? '1') !== '1') {
            return null; // in trash or deleted
        }

        return [
            'id' => (string) $response->json('data.id'),
            'name' => (string) ($attributes['name'] ?? ''),
            'size' => (int) ($attributes['storage_info']['size_in_bytes'] ?? $attributes['size_in_bytes'] ?? 0),
            'md5' => null,
            'sha1' => null,
        ];
    }

    public function trash(string $id): void
    {
        $response = $this->api()->withBody(json_encode(['data' => ['attributes' => ['status' => '51'], 'type' => 'files']]), 'application/vnd.api+json')
            ->patch($this->apiBase().'/files/'.rawurlencode($id));
        if (! $response->successful() && $response->status() !== 404) {
            $this->fail($response, __('removing the old copy'));
        }
    }

    public function download(string $id, ?string $range = null): Response
    {
        return $this->http->withHeaders(array_filter(['Authorization' => 'Zoho-oauthtoken '.$this->accessToken(), 'Range' => $range]))
            ->withOptions(['stream' => true])->timeout(0)->get($this->downloadBase().rawurlencode($id));
    }
}

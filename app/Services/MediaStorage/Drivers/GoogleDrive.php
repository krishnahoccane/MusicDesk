<?php

namespace App\Services\MediaStorage\Drivers;

use App\Services\MediaStorage\MediaStorageException;
use Illuminate\Http\Client\Response;

/**
 * Google Drive API v3 (https://developers.google.com/drive/api/reference/rest/v3).
 * Scope "drive" so existing folders can be chosen; shared drives supported.
 */
class GoogleDrive extends CloudDrive
{
    protected const API = 'https://www.googleapis.com/drive/v3';

    protected const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public static function type(): string
    {
        return 'google_drive';
    }

    public static function label(): string
    {
        return 'Google Drive';
    }

    public static function fields(): array
    {
        return [
            'client_id' => ['label' => __('Client ID'), 'type' => 'text', 'required' => true],
            'client_secret' => ['label' => __('Client secret'), 'type' => 'password', 'required' => true, 'secret' => true],
        ];
    }

    public function rootName(): string
    {
        return __('My Drive');
    }

    public function rootId(): string
    {
        return 'root';
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => (string) $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive openid email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->asForm()->acceptJson()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => (string) $this->setting('client_id'),
            'client_secret' => (string) $this->setting('client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('refresh_token'))) {
            $this->fail($response, __('connecting'));
        }

        $about = $this->http->withToken((string) $response->json('access_token'))->acceptJson()->timeout(30)
            ->get(self::API.'/about', ['fields' => 'user(emailAddress,displayName)']);

        return [
            'refresh_token' => (string) $response->json('refresh_token'),
            'account' => (string) ($about->json('user.emailAddress') ?? $about->json('user.displayName') ?? 'Google account'),
        ];
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function refreshParameters(): array
    {
        return [];
    }

    public function listFolders(string $parentId): array
    {
        $folders = [];
        $pageToken = null;

        do {
            $response = $this->api()->get(self::API.'/files', array_filter([
                'q' => sprintf("'%s' in parents and mimeType = '%s' and trashed = false", $this->escape($parentId), self::FOLDER_MIME),
                'fields' => 'nextPageToken, files(id, name)',
                'orderBy' => 'name',
                'pageSize' => 200,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
                'pageToken' => $pageToken,
            ]));
            $response->successful() || $this->fail($response, __('listing folders'));

            foreach ((array) $response->json('files', []) as $file) {
                $folders[] = ['id' => (string) $file['id'], 'name' => (string) $file['name']];
            }
            $pageToken = $response->json('nextPageToken');
        } while ($pageToken && count($folders) < 2000);

        return $folders;
    }

    public function createFolder(string $name, string $parentId): string
    {
        $response = $this->api()->withQueryParameters(['supportsAllDrives' => 'true', 'fields' => 'id'])
            ->post(self::API.'/files', ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]]);
        $response->successful() || $this->fail($response, __('creating a folder'));

        return (string) $response->json('id');
    }

    public function findOrCreateFolder(string $name, string $parentId): string
    {
        $response = $this->api()->get(self::API.'/files', [
            'q' => sprintf("name = '%s' and '%s' in parents and mimeType = '%s' and trashed = false", $this->escape($name), $this->escape($parentId), self::FOLDER_MIME),
            'fields' => 'files(id)',
            'pageSize' => 1,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);
        $response->successful() || $this->fail($response, __('finding a folder'));

        return (string) ($response->json('files.0.id') ?? $this->createFolder($name, $parentId));
    }

    /**
     * Resumable upload in CHUNK-sized pieces (works for multi-GB masters).
     */
    public function upload(string $localPath, string $name, string $folderId, string $mime): array
    {
        $size = (int) filesize($localPath);

        $start = $this->api()
            ->withHeaders(['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $size])
            ->withQueryParameters(['uploadType' => 'resumable', 'supportsAllDrives' => 'true', 'fields' => 'id,name,size,md5Checksum'])
            ->post(self::UPLOAD, ['name' => $name, 'parents' => [$folderId]]);
        $start->successful() || $this->fail($start, __('starting the upload'));

        $session = $start->header('Location');
        if ($session === '') {
            throw new MediaStorageException(__('Google Drive did not return an upload session.'));
        }

        $last = null;
        if ($size === 0) {
            $last = $this->http->withHeaders(['Content-Range' => 'bytes */0'])->withBody('', $mime)->timeout(120)->put($session);
        }

        foreach ($this->chunks($localPath) as [$offset, $bytes]) {
            $end = $offset + strlen($bytes) - 1;
            // 308 = "resume incomplete", not a redirect.
            $last = $this->http->withOptions(['allow_redirects' => false])
                ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                ->withBody($bytes, $mime)
                ->timeout(300)
                ->put($session);

            if ($last->status() !== 308 && ! $last->successful()) {
                $this->fail($last, __('uploading'));
            }
        }

        if (! $last?->successful()) {
            throw new MediaStorageException(__('Google Drive did not confirm the upload.'));
        }

        return $this->normalize((array) $last->json());
    }

    public function update(string $id, ?string $name, ?string $folderId, ?string $oldFolderId): string
    {
        $query = ['supportsAllDrives' => 'true', 'fields' => 'id'];
        if ($folderId && $oldFolderId && $folderId !== $oldFolderId) {
            $query['addParents'] = $folderId;
            $query['removeParents'] = $oldFolderId;
        }

        $response = $this->api()->withQueryParameters($query)->patch(self::API.'/files/'.rawurlencode($id), array_filter(['name' => $name]) ?: new \stdClass);
        $response->successful() || $this->fail($response, __('renaming'));

        return $id;
    }

    public function metadata(string $id): ?array
    {
        $response = $this->api()->get(self::API.'/files/'.rawurlencode($id), ['fields' => 'id,name,size,md5Checksum,trashed', 'supportsAllDrives' => 'true']);
        if ($response->status() === 404) {
            return null;
        }
        $response->successful() || $this->fail($response, __('checking a file'));

        return $response->json('trashed') ? null : $this->normalize((array) $response->json());
    }

    public function trash(string $id): void
    {
        $response = $this->api()->withQueryParameters(['supportsAllDrives' => 'true'])->patch(self::API.'/files/'.rawurlencode($id), ['trashed' => true]);
        if (! $response->successful() && $response->status() !== 404) {
            $this->fail($response, __('removing the old copy'));
        }
    }

    public function download(string $id, ?string $range = null): Response
    {
        return $this->http->withToken($this->accessToken())->withOptions(['stream' => true])->timeout(0)
            ->withHeaders(array_filter(['Range' => $range]))
            ->get(self::API.'/files/'.rawurlencode($id), ['alt' => 'media', 'supportsAllDrives' => 'true']);
    }

    /**
     * @return array{id: string, name: string, size: int, md5: ?string, sha1: ?string}
     */
    protected function normalize(array $file): array
    {
        return [
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['name'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'md5' => isset($file['md5Checksum']) ? strtolower((string) $file['md5Checksum']) : null,
            'sha1' => null,
        ];
    }

    /** Drive query strings: escape backslashes and single quotes. */
    protected function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}

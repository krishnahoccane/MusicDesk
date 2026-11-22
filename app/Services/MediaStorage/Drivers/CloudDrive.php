<?php

namespace App\Services\MediaStorage\Drivers;

use App\Models\MediaStorageTarget;
use App\Services\MediaStorage\MediaStorageException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * A storage destination reached over its API. OAuth drives (Google Drive,
 * OneDrive, Dropbox, Zoho WorkDrive) use the authorization-code flow with a
 * refresh token; FTP overrides the connection parts. Credentials live
 * encrypted on the MediaStorageTarget; access tokens are cached per target.
 */
abstract class CloudDrive
{
    /** Upload chunk size: a multiple of 256 KiB (Google), 320 KiB (Microsoft) and 4 MiB-friendly. */
    public const CHUNK = 10 * 1024 * 1024;

    public function __construct(protected MediaStorageTarget $target, protected HttpFactory $http) {}

    abstract public static function type(): string;

    abstract public static function label(): string;

    /**
     * Credential fields shown when adding / editing the destination.
     *
     * @return array<string, array{label: string, type: string, required?: bool, default?: string, options?: array<string, string>, help?: string, secret?: bool}>
     */
    abstract public static function fields(): array;

    public static function usesOAuth(): bool
    {
        return true;
    }

    /** Remote ids are paths (FTP, Dropbox folders): renaming a folder changes its files' ids. */
    public static function pathBased(): bool
    {
        return false;
    }

    public function target(): MediaStorageTarget
    {
        return $this->target;
    }

    abstract public function rootId(): string;

    public function rootName(): string
    {
        return static::label();
    }

    /** @return list<array{id: string, name: string}> */
    abstract public function listFolders(string $parentId): array;

    abstract public function createFolder(string $name, string $parentId): string;

    /** Existing folder with this exact name in the parent, or a new one. Returns its id. */
    abstract public function findOrCreateFolder(string $name, string $parentId): string;

    /** Renames a folder; returns its (possibly new) id. */
    public function renameFolder(string $id, string $name, string $parentId): string
    {
        $this->update($id, $name, null, null);

        return $id;
    }

    /** @return array{id: string, name: string, size: int, md5: ?string, sha1: ?string, hash?: ?string} */
    abstract public function upload(string $localPath, string $name, string $folderId, string $mime): array;

    /** Rename and/or move a file; returns its (possibly new) id. */
    abstract public function update(string $id, ?string $name, ?string $folderId, ?string $oldFolderId): string;

    /** @return array{id: string, name: string, size: int, md5: ?string, sha1: ?string, hash?: ?string}|null null when missing or trashed */
    abstract public function metadata(string $id): ?array;

    abstract public function trash(string $id): void;

    /** Streams the file (optionally a byte range). */
    abstract public function download(string $id, ?string $range = null): Response;

    /** Is the remote copy identical to the local file? */
    public function verify(string $localPath, array $remote): bool
    {
        if ($remote['size'] !== (int) filesize($localPath)) {
            return false;
        }
        if (($remote['md5'] ?? null) !== null && $remote['md5'] !== md5_file($localPath)) {
            return false;
        }
        if (($remote['sha1'] ?? null) !== null && $remote['sha1'] !== strtolower((string) sha1_file($localPath))) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------ OAuth

    abstract public function authorizeUrl(string $redirectUri, string $state): string;

    /** @return array{refresh_token: string, account: string} */
    abstract public function exchangeCode(string $code, string $redirectUri): array;

    abstract protected function tokenUrl(): string;

    /** @return array<string, string> */
    protected function refreshParameters(): array
    {
        return [];
    }

    /** For destinations without OAuth (FTP): test and return an account label. */
    public function testConnection(): string
    {
        $this->listFolders($this->rootId());

        return (string) $this->target->account;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->target->credential($key, $default);
    }

    public function hasCredentials(): bool
    {
        foreach (static::fields() as $key => $field) {
            if (($field['required'] ?? false) && blank($this->setting($key))) {
                return false;
            }
        }

        return true;
    }

    public function isConnected(): bool
    {
        return $this->hasCredentials() && (static::usesOAuth() ? filled($this->setting('refresh_token')) : $this->target->connected_at !== null);
    }

    public function folderId(): ?string
    {
        return filled($this->target->folder_id) ? (string) $this->target->folder_id : null;
    }

    public function isReady(): bool
    {
        return $this->isConnected() && $this->folderId() !== null;
    }

    protected function cacheKey(): string
    {
        return 'labeldesk:media:target:'.$this->target->id.':access';
    }

    protected function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (! $this->isConnected()) {
            throw new MediaStorageException(__(':name is not connected.', ['name' => $this->target->name]));
        }

        $response = $this->http->asForm()->acceptJson()->timeout(30)->post($this->tokenUrl(), $this->refreshParameters() + [
            'client_id' => (string) $this->setting('client_id'),
            'client_secret' => (string) $this->setting('client_secret'),
            'refresh_token' => (string) $this->setting('refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            $error = (string) ($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status());
            throw new MediaStorageException(__(':name refused the stored connection (:reason). Connect it again in Settings → Media storage.', [
                'name' => $this->target->name, 'reason' => mb_substr($error, 0, 160),
            ]));
        }

        // Some providers rotate refresh tokens: keep the newest one.
        if (is_string($response->json('refresh_token')) && $response->json('refresh_token') !== $this->setting('refresh_token')) {
            $this->target->setCredentials(['refresh_token' => $response->json('refresh_token')]);
            $this->target->save();
        }

        $token = (string) $response->json('access_token');
        Cache::put($this->cacheKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    public function forgetAccessToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    protected function api(): PendingRequest
    {
        return $this->http->withToken($this->accessToken())->acceptJson()->timeout(60)->connectTimeout(15);
    }

    protected function fail(Response $response, string $action): never
    {
        $message = $response->json('error.message') ?? $response->json('error_summary') ?? $response->json('errors.0.title')
            ?? $response->json('error_description') ?? $response->json('error') ?? $response->body();
        if (is_array($message)) {
            $message = json_encode($message);
        }

        throw new MediaStorageException(__(':drive: :action failed (HTTP :status): :reason', [
            'drive' => $this->target->name ?: static::label(), 'action' => $action, 'status' => $response->status(), 'reason' => mb_substr(strip_tags((string) $message), 0, 200),
        ]));
    }

    /**
     * @return \Generator<int, array{0: int, 1: string}> offset => bytes
     */
    protected function chunks(string $path, int $size = self::CHUNK): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new MediaStorageException(__('The local file could not be read.'));
        }

        try {
            $offset = 0;
            while (! feof($handle)) {
                $bytes = fread($handle, $size);
                if ($bytes === false || $bytes === '') {
                    break;
                }
                yield [$offset, $bytes];
                $offset += strlen($bytes);
            }
        } finally {
            fclose($handle);
        }
    }
}

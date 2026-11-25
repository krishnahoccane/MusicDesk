<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * COSMOS data ingestion API (v2.0). Every call signs in with the stored
 * email and password (POST /user/system/signin) and sends the bearer token;
 * the token is cached encrypted and renewed once when COSMOS answers 401/403.
 *
 * Nothing here logs: request bodies hold the password, responses hold
 * tokens and presigned S3 URLs. Errors carry PDL's message only.
 */
class CosmosClient
{
    /** Expiry PDL gave with the last token (oauth2_token.expiry), if any. */
    protected ?CarbonImmutable $tokenExpiry = null;

    public function __construct(protected PdlSettings $settings) {}

    // ------------------------------------------------------------ authentication

    /** Sign in with explicit details (Settings → Test connection). Returns nothing on success. */
    public function testSignIn(string $baseUrl, string $email, string $password): void
    {
        $this->requestToken($baseUrl, $email, $password);
    }

    public function token(bool $fresh = false): string
    {
        $email = (string) $this->settings->get('email');
        $password = $this->settings->password();
        if ($email === '' || $password === null) {
            throw new CosmosException(__('Enter the PDL account email and password in Settings → PDL distribution.'));
        }

        $key = $this->tokenKey();
        if (! $fresh && ($cached = Cache::get($key))) {
            $token = rescue(fn () => Crypt::decryptString($cached), null, false);
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        $token = $this->requestToken($this->settings->baseUrl(), $email, $password);
        // Until PDL's own expiry (less 5 minutes), else the configured lifetime.
        $until = $this->tokenExpiry?->subMinutes(5);
        if ($until === null || $until->lessThan(now()->addMinute())) {
            $until = now()->addMinutes(max(1, (int) $this->settings->get('token_minutes')));
        }
        Cache::put($key, Crypt::encryptString($token), $until);

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    protected function tokenKey(): string
    {
        return 'pdl:token:'.sha1($this->settings->baseUrl().'|'.mb_strtolower((string) $this->settings->get('email')));
    }

    protected function requestToken(string $baseUrl, string $email, string $password): string
    {
        $base = rtrim($baseUrl, '/');
        $url = (str_ends_with($base, Cosmos::API_PREFIX) ? $base : $base.Cosmos::API_PREFIX).'/user/system/signin';

        try {
            $response = $this->http()->post($url, ['email' => $email, 'password' => $password]);
        } catch (ConnectionException $e) {
            throw new CosmosException(__('Could not reach PDL at :host. Check the API base URL and try again.', ['host' => parse_url($base, PHP_URL_HOST) ?: $base]), true);
        }

        $json = $response->json();
        if ($response->status() === 401 || $response->status() === 403 || (is_array($json) && ($json['success'] ?? null) === false)) {
            $message = is_array($json) ? $this->messageFrom($json) : null;
            throw new CosmosException(__('PDL refused the sign-in: :message', ['message' => $message ?? __('check the email and password')]), false, null, $response->status());
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new CosmosException(__('PDL could not sign in right now (HTTP :status). Try again later.', ['status' => $response->status()]), true, null, $response->status());
        }

        $token = $this->findToken(is_array($json) ? $json : []) ?? $this->bearerFromHeader($response);
        $this->tokenExpiry = $this->findExpiry(is_array($json) ? $json : []);
        if ($token === null) {
            throw new CosmosException(__('PDL accepted the sign-in but its answer had no access token (HTTP :status).', ['status' => $response->status()]), false, null, $response->status());
        }

        return $token;
    }

    /** @param  array<string, mixed>  $json */
    protected function findToken(array $json): ?string
    {
        // COSMOS: data.data.oauth2_token.access_token (token_type "Bearer", expiry ISO 8601).
        foreach (['data.data.oauth2_token.access_token', 'data.oauth2_token.access_token', 'oauth2_token.access_token', 'data.token', 'data.access_token', 'data.accessToken', 'data.user.token', 'data.jwt', 'token', 'access_token', 'accessToken'] as $path) {
            $value = data_get($json, $path);
            if (is_string($value) && strlen($value) >= 8) {
                return preg_replace('/^Bearer\s+/i', '', $value);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $json */
    protected function findExpiry(array $json): ?CarbonImmutable
    {
        foreach (['data.data.oauth2_token.expiry', 'data.oauth2_token.expiry', 'oauth2_token.expiry'] as $path) {
            $value = data_get($json, $path);
            if (is_string($value) && $value !== '') {
                return rescue(fn () => CarbonImmutable::parse($value), null, false);
            }
        }

        return null;
    }

    protected function bearerFromHeader(Response $response): ?string
    {
        $header = (string) $response->header('Authorization');

        return preg_match('/^Bearer\s+(\S{8,})$/i', $header, $m) ? $m[1] : null;
    }

    // ------------------------------------------------------------ album endpoints

    /**
     * POST /album/add/meta: validates the metadata and returns the ingestion
     * token and presigned upload URLs (data.token, data.signed_albums).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function addMeta(array $body): array
    {
        return $this->call('post', 'album/add/meta', $body);
    }

    /**
     * GET /album/verify/meta: after the uploads; on success the album is
     * ingested into COSMOS and its album id returned.
     *
     * @return array<string, mixed>
     */
    public function verify(string $token, string $labelId, string $albumId = ''): array
    {
        return $this->call('get', 'album/verify/meta', ['album_id' => $albumId, 'label_id' => $labelId, 'token' => $token]);
    }

    /** @return array<string, mixed> */
    public function submit(string $albumId, string $platforms, bool $allowAnyGoLiveDate): array
    {
        return $this->call('post', 'album/pdl/submit', ['id' => $albumId, 'platforms_to_release' => $platforms, 'allow_any_go_live_date' => $allowAnyGoLiveDate]);
    }

    /**
     * Albums containing a track with this ISRC (GET /album/page?isrc=).
     *
     * @return list<array<string, mixed>>
     */
    public function albumsByIsrc(string $isrc): array
    {
        $data = $this->call('get', 'album/page', ['curr_page' => 1, 'page_off_set' => 15, 'sort_by' => '-_id', 'isrc' => $isrc]);

        return self::albumsIn($data);
    }

    /** @return array<string, mixed>|null the album with this COSMOS id, found through one of its ISRCs */
    public function album(string $albumId, string $isrc): ?array
    {
        foreach ($this->albumsByIsrc($isrc) as $album) {
            if ((string) ($album['id'] ?? $album['_id'] ?? '') === $albumId) {
                return $album;
            }
        }

        return null;
    }

    /** @return list<string> issues reported by the PDL team (newest last) */
    public function reportedIssues(string $albumId): array
    {
        try {
            $data = $this->call('get', 'reportissue/getbyid', ['album_id' => $albumId]);
        } catch (CosmosException $e) {
            if ($e->retryable) {
                throw $e;
            }

            return [];
        }

        $details = data_get($data, 'report_albumissue_details') ?? data_get($data, '0.report_albumissue_details') ?? data_get($data, 'issues') ?? [];
        $comments = [];
        foreach ((array) $details as $row) {
            $comment = is_array($row) ? ($row['report_comment'] ?? $row['comment'] ?? null) : null;
            if (is_string($comment) && trim($comment) !== '') {
                $date = is_array($row) && is_string($row['date_of_reporting'] ?? null) ? $row['date_of_reporting'].': ' : '';
                $comments[] = $date.trim($comment);
            }
        }

        return $comments;
    }

    /** GET /album/media/signedurl: a presigned URL to replace an album's artwork (type "Artwork"). */
    public function replacementUrl(string $albumId, string $type, int $size): string
    {
        $data = $this->call('get', 'album/media/signedurl', ['id' => $albumId, 'type' => $type, 'size' => $size]);
        $url = $data['url'] ?? $data['signed_url'] ?? null;
        if (! is_string($url) || ! str_starts_with($url, 'http')) {
            throw new CosmosException(__('PDL did not return an upload link for the new :type.', ['type' => mb_strtolower($type)]));
        }

        return $url;
    }

    /**
     * PUT /album/media/update with the complete album (before submission, or
     * after PDL reported an issue).
     *
     * @param  array<string, mixed>  $album
     * @return array<string, mixed>
     */
    public function updateAlbum(array $album): array
    {
        return $this->call('put', 'album/media/update', ['one' => 'media', 'two' => 'update', 'album' => $album]);
    }

    // ------------------------------------------------------------ uploads

    /**
     * Upload a file to a presigned S3 URL in one PUT (no multipart), streamed
     * from disk. S3 returns the MD5 as ETag for single-part uploads: checked.
     */
    public function upload(string $url, string $path, int $size, string $md5): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new CosmosException(__('The file :name could not be read on this server.', ['name' => basename($path)]));
        }

        try {
            $response = Http::connectTimeout(20)
                ->timeout(max(300, (int) ceil($size / (128 * 1024)))) // allows ~128 KB/s
                ->withHeaders(['Content-Length' => (string) $size])
                ->withBody(Utils::streamFor($handle), 'application/octet-stream')
                ->put($url);
        } catch (ConnectionException $e) {
            throw new CosmosException(__('The upload to PDL\'s storage was interrupted. It will be tried again.'), true);
        } finally {
            is_resource($handle) && fclose($handle);
        }

        if (! $response->successful()) {
            $code = $this->xmlValue($response->body(), 'Code');
            $message = $this->xmlValue($response->body(), 'Message');
            $expired = $response->status() === 403 && ($code === 'AccessDenied' || $code === 'ExpiredToken') && str_contains((string) $message, 'expired');
            throw new CosmosException($expired
                ? __('The upload link from PDL has expired. Send the release again to get new links.')
                : __('PDL\'s storage refused the upload (HTTP :status:detail).', ['status' => $response->status(), 'detail' => $code ? ', '.$code.($message ? ': '.$message : '') : '']),
                $response->serverError() || $response->status() === 429, null, $response->status());
        }

        $etag = strtolower(trim((string) $response->header('ETag'), '" '));
        if (preg_match('/^[a-f0-9]{32}$/', $etag) && $etag !== strtolower($md5)) {
            throw new CosmosException(__('The uploaded copy of :name does not match the file on the server (checksum). It will be uploaded again.', ['name' => basename($path)]), true);
        }
    }

    // ------------------------------------------------------------ plumbing

    /**
     * @param  array<string, mixed>  $data  JSON body (post/put) or query (get)
     * @return array<string, mixed> the "data" member of a successful answer
     */
    protected function call(string $method, string $path, array $data = [], bool $retried = false): array
    {
        $url = $this->settings->apiUrl($path);
        $token = $this->token();

        try {
            $request = $this->http()->withToken($token);
            $response = $method === 'get' ? $request->get($url, $data) : $request->{$method}($url, $data);
        } catch (ConnectionException $e) {
            throw new CosmosException(__('Could not reach PDL. It will be tried again.'), true);
        }

        $json = $response->json();
        $denied = in_array($response->status(), [401, 403], true)
            || (is_array($json) && ($json['success'] ?? null) === false && (int) data_get($json, 'data.code', -1) === 403);
        if ($denied && ! $retried) {
            $this->forgetToken();
            $this->token(fresh: true);

            return $this->call($method, $path, $data, true);
        }

        if (is_array($json) && array_key_exists('success', $json)) {
            if ($json['success'] === true || $json['success'] === 'true') {
                $payload = $json['data'] ?? [];

                return is_array($payload) ? $payload : ['value' => $payload];
            }
            $code = data_get($json, 'data.code');
            $code = is_numeric($code) ? (int) $code : null;
            $message = $this->messageFrom($json) ?? __('PDL reported an error (HTTP :status).', ['status' => $response->status()]);

            throw new CosmosException($message, $code === 5 || $response->serverError(), $code, $response->status());
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new CosmosException(__('PDL is not available right now (HTTP :status). It will be tried again.', ['status' => $response->status()]), true, null, $response->status());
        }
        if (! $response->successful()) {
            throw new CosmosException(__('PDL refused the request (HTTP :status).', ['status' => $response->status()]), false, null, $response->status());
        }

        // Successful HTTP answer without the usual {success, data} envelope.
        return is_array($json) ? $json : throw new CosmosException(__('PDL returned an answer that could not be read (HTTP :status).', ['status' => $response->status()]), true);
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->connectTimeout(15)->timeout(60)
            ->withUserAgent('LabelDesk PDL distribution/1.0');
    }

    /** @param  array<string, mixed>  $json */
    protected function messageFrom(array $json): ?string
    {
        foreach (['data.msg', 'data.message', 'msg', 'message', 'error', 'data.err', 'data.error'] as $path) {
            $value = data_get($json, $path);
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 500);
            }
            if (is_array($value) && $value !== []) {
                $flat = implode('; ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value)));
                if ($flat !== '') {
                    return mb_substr($flat, 0, 500);
                }
            }
        }

        return null;
    }

    protected function xmlValue(string $xml, string $tag): ?string
    {
        return preg_match('#<'.$tag.'>([^<]{1,300})</'.$tag.'>#', $xml, $m) ? html_entity_decode($m[1]) : null;
    }

    /**
     * The album records in a listing answer (shape not fixed by the guide:
     * looks for the first list of objects that have an id and songs or a name).
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function albumsIn(array $data, int $depth = 0): array
    {
        if (array_is_list($data) && $data !== [] && is_array($data[0] ?? null)
            && (isset($data[0]['id']) || isset($data[0]['_id'])) && (isset($data[0]['songs']) || isset($data[0]['name']))) {
            return $data;
        }
        if ($depth >= 3) {
            return [];
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = self::albumsIn($value, $depth + 1)) !== []) {
                return $found;
            }
        }

        return [];
    }

    /** @param  array<string, mixed>  $data */
    public static function albumIdIn(array $data): ?string
    {
        foreach (['album_id', 'albumId', 'id', '_id', 'album.id', 'albums.0.id', 'signed_albums.0.id', 'album.0.id'] as $path) {
            $value = data_get($data, $path);
            if (is_string($value) && preg_match('/^[a-f0-9]{24}$/i', $value)) {
                return $value;
            }
        }

        return null;
    }

    public static function safe(Throwable $e): string
    {
        return $e instanceof CosmosException ? $e->getMessage() : __('Unexpected error (:type).', ['type' => class_basename($e)]);
    }
}

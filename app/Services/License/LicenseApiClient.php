<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * HTTPS client for the MusicDesk.tech license server (api/license/v1).
 *
 * - HTTPS only (plain HTTP is refused).
 * - JSON in, JSON out. Errors: { "error": { "code", "message", "field" } }.
 * - Connection failures and 5xx responses are retried a little; 429 is not.
 * - The license key and tokens are never logged.
 */
class LicenseApiClient
{
    public function __construct(protected HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> Decoded successful response body ([] for 204)
     *
     * @throws LicenseException
     */
    public function post(string $endpoint, array $body): array
    {
        $url = $this->url($endpoint);

        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->asJson()
                ->connectTimeout((int) config('licensing.connection_timeout', 5))
                ->timeout((int) config('licensing.verification_timeout', 15))
                ->retry(
                    (int) config('licensing.retries', 2) + 1,
                    (int) config('licensing.retry_delay_ms', 750),
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->post($url, $body);
        } catch (Throwable) {
            throw LicenseException::unreachable();
        }

        return $this->parse($response);
    }

    /**
     * Download a package (update ZIP) to $sink. Errors are answered with the usual JSON
     * error body and raise the same exceptions as post().
     *
     * @param  array<string, mixed>  $body
     * @return array{sha256: string, bytes: int}
     *
     * @throws LicenseException
     */
    public function download(string $endpoint, array $body, string $sink): array
    {
        $url = $this->url($endpoint);

        try {
            $response = $this->http
                ->withHeaders(['Accept' => 'application/zip, application/json'] + $this->headers())
                ->asJson()
                ->connectTimeout((int) config('licensing.connection_timeout', 5))
                ->timeout(600)
                ->sink($sink)
                ->post($url, $body);
        } catch (Throwable) {
            @unlink($sink);
            throw LicenseException::unreachable();
        }

        if (! $response->successful()) {
            try {
                $this->parse($response);
            } finally {
                @unlink($sink);
            }
            throw LicenseException::invalidResponse();
        }

        // Http::fake() returns the body without writing the sink.
        if (! is_file($sink) || filesize($sink) === 0) {
            file_put_contents($sink, $response->body());
        }

        return ['sha256' => (string) hash_file('sha256', $sink), 'bytes' => (int) filesize($sink)];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->http->withHeaders($this->headers())
                ->connectTimeout((int) config('licensing.connection_timeout', 5))
                ->timeout((int) config('licensing.verification_timeout', 15))
                ->get($this->url($endpoint));
        } catch (Throwable) {
            throw LicenseException::unreachable();
        }

        return $this->parse($response);
    }

    protected function url(string $endpoint): string
    {
        $base = LicenseAuthority::apiBaseUrl();
        $path = LicenseAuthority::endpoint($endpoint);
        $url = $base.'/'.ltrim($path, '/');

        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new LicenseException('insecure_endpoint', __('The licensing endpoint must use HTTPS.'));
        }

        return $url;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'LabelDesk/'.config('labeldesk.product.version'),
            'X-Request-Id' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parse(Response $response): array
    {
        if ($response->status() === 204) {
            return [];
        }

        $data = $response->json();

        if ($response->successful()) {
            if (! is_array($data)) {
                throw LicenseException::invalidResponse();
            }

            return $data;
        }

        $code = is_array($data) ? (string) ($data['error']['code'] ?? '') : '';

        // Not an answer from the license server itself (no JSON error):
        // a firewall / CDN / proxy in between. Say so, with a reference.
        if ($code === '' && $response->status() !== 429) {
            $this->reportForeignResponse($response);

            if ($this->blockedByCloudflare($response)) {
                throw LicenseException::blockedByFirewall($response->status(), $response->header('CF-RAY') ?: null);
            }

            if ($response->serverError()) {
                throw LicenseException::unreachable();
            }

            if ($response->status() === 404) {
                throw LicenseException::wrongEndpoint(LicenseAuthority::apiBaseUrl());
            }

            throw LicenseException::unexpectedResponse($response->status());
        }

        if ($response->serverError()) {
            throw LicenseException::unreachable();
        }

        if ($code === '') {
            $code = 'rate_limited';
        }
        $message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : null;

        throw LicenseException::fromApi($code, $message);
    }

    /**
     * Cloudflare challenge / block pages (Bot Fight Mode, WAF, Browser Integrity Check, IP rules).
     */
    protected function blockedByCloudflare(Response $response): bool
    {
        if ($response->header('cf-mitigated') !== '') {
            return true;
        }

        $fromCloudflare = str_contains(strtolower($response->header('Server')), 'cloudflare') || $response->header('CF-RAY') !== '';
        $body = strtolower(substr($response->body(), 0, 4000));

        return $fromCloudflare && in_array($response->status(), [403, 429, 503], true)
            && ! str_contains(strtolower($response->header('Content-Type')), 'json')
            && (str_contains($body, 'cloudflare') || str_contains($body, 'challenge') || str_contains($body, 'attention required') || $body === '');
    }

    /**
     * Logged without the request body (no key, no token).
     */
    protected function reportForeignResponse(Response $response): void
    {
        Log::warning('License server request was answered by something other than the license server', [
            'status' => $response->status(),
            'server' => $response->header('Server'),
            'content_type' => $response->header('Content-Type'),
            'cf_ray' => $response->header('CF-RAY'),
            'cf_mitigated' => $response->header('cf-mitigated'),
            'body_start' => Str::limit(trim(strip_tags($response->body())), 160),
        ]);
    }
}

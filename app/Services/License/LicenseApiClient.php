<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
        $base = (string) config('licensing.api_base_url');
        $path = (string) config("licensing.endpoints.{$endpoint}", '/'.$endpoint);
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

        if ($response->serverError()) {
            throw LicenseException::unreachable();
        }

        $code = is_array($data) ? (string) ($data['error']['code'] ?? '') : '';
        if ($code === '') {
            $code = $response->status() === 429 ? 'rate_limited' : 'server_error';
        }
        $message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : null;

        throw LicenseException::fromApi($code, $message);
    }
}

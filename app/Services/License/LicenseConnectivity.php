<?php

namespace App\Services\License;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * "Test connection" for the license server (installer license step and
 * `php artisan labeldesk:license:diagnose`). Uses a dummy key, never the
 * customer's key, and only talks to the configured license server host.
 */
class LicenseConnectivity
{
    public const DUMMY_KEY = 'LDSK-TEST-TEST-TEST-TEST';

    public function __construct(protected HttpFactory $http) {}

    /**
     * @return array{ok: bool, checks: list<array{label: string, ok: bool, detail: string}>, ip: ?string, ray: ?string, advice: ?string}
     */
    public function diagnose(string $domain): array
    {
        $base = LicenseAuthority::apiBaseUrl();
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $base);
        $checks = [];
        $ray = null;
        $advice = null;

        // 1. Public key endpoint (GET): plain HTTPS reachability and the expected signing key.
        try {
            $r = $this->client()->get($base.LicenseAuthority::endpoint('public_key'));
            $ray = $r->header('CF-RAY') ?: $ray;
            $kidOk = is_array($r->json()) && ($r->json('public_key') === LicenseAuthority::publicKey());
            $checks[] = ['label' => __('Reach the license server'), 'ok' => $r->successful() && $kidOk, 'detail' => $this->describe($r, $kidOk ? __('signing key matches') : __('unexpected answer'))];
        } catch (Throwable $e) {
            $checks[] = ['label' => __('Reach the license server'), 'ok' => false, 'detail' => __('No connection: :reason', ['reason' => $this->short($e->getMessage())])];
            $advice = __('This server cannot open HTTPS connections to :host. Ask your hosting provider to allow outgoing HTTPS (port 443), or use offline activation.', ['host' => parse_url($base, PHP_URL_HOST)]);

            return ['ok' => false, 'checks' => $checks, 'ip' => null, 'ray' => null, 'advice' => $advice];
        }

        // 2. Activation endpoint (POST, like a real activation) with a dummy key: a healthy server answers "invalid_key" in JSON.
        try {
            $r = $this->client()->asJson()->post($base.LicenseAuthority::endpoint('activate'), [
                'key' => self::DUMMY_KEY, 'domain' => $domain, 'version' => (string) config('labeldesk.product.version'),
                'php_version' => PHP_VERSION, 'instance_id' => 'connection-test',
            ]);
            $ray = $r->header('CF-RAY') ?: $ray;
            $answered = is_array($r->json()) && isset($r->json()['error']['code']);
            $checks[] = ['label' => __('Send an activation request'), 'ok' => $answered, 'detail' => $this->describe($r, $answered ? __('the license server answered (":code")', ['code' => $r->json('error.code')]) : null)];

            if (! $answered) {
                $advice = $r->header('cf-mitigated') !== '' || str_contains(strtolower($r->header('Server')), 'cloudflare')
                    ? __('Cloudflare is blocking license requests from this server. On musicdesk.tech, allow the path /api/license/ (turn off Bot Fight Mode, or add a WAF rule that skips bot protection and Browser Integrity Check for it), or allow this server\'s IP address.')
                    : __('Something between this server and MusicDesk.tech is answering instead of the license server. Check outgoing proxies or firewalls on the hosting account.');
            }
        } catch (Throwable $e) {
            $checks[] = ['label' => __('Send an activation request'), 'ok' => false, 'detail' => __('No connection: :reason', ['reason' => $this->short($e->getMessage())])];
        }

        // 3. This server's public IP as seen by the CDN in front of the license server (same host, no third party).
        $ip = null;
        try {
            $trace = $this->client()->get($origin.'/cdn-cgi/trace');
            if ($trace->successful() && preg_match('/^ip=([0-9a-fA-F:.]+)
?$/m', $trace->body(), $m)) {
                $ip = $m[1];
            }
        } catch (Throwable) {
            // Optional information only.
        }

        return [
            'ok' => collect($checks)->every(fn ($c) => $c['ok']),
            'checks' => $checks,
            'ip' => $ip,
            'ray' => $ray,
            'advice' => $advice,
        ];
    }

    protected function client()
    {
        return $this->http->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'LabelDesk/'.config('labeldesk.product.version'),
        ])->connectTimeout(8)->timeout(15);
    }

    protected function describe(Response $r, ?string $note): string
    {
        $server = $r->header('Server');
        $type = strtok($r->header('Content-Type') ?: '—', ';');
        $parts = ['HTTP '.$r->status(), $type];
        if ($server !== '') {
            $parts[] = $server;
        }
        if ($r->header('cf-mitigated') !== '') {
            $parts[] = 'cf-mitigated: '.$r->header('cf-mitigated');
        }

        return implode(' · ', $parts).($note ? ' — '.$note : '');
    }

    protected function short(string $message): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 180);
    }
}

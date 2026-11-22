<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use App\Models\LicenseActivation;
use App\Support\PhpMailer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports every failed license check: an email through PHP mail() and a
 * webhook (domain_name, error, mail_sent, ip_address) — neither carries the
 * license key or tokens — plus the license server's security webhook
 * (POST /report, at most once an hour per reason, as its spec asks). A
 * "lock" answer from the license server locks this installation. Recipient
 * and webhooks are fixed in code, like the rest of the license authority.
 * Never throws.
 */
class LicenseFailureAlert
{
    public const EMAIL = 'kh@adprana.com';

    public const WEBHOOK = 'https://script.google.com/macros/s/AKfycbyiDTGGx9GwlCkouu7nK5ff07hy7BuMWNSOENjpk79s69D5z01Cp5AXSGXUTIRZbQPebw/exec';

    /** Reasons the license server's /report endpoint understands. */
    public const REPORT_REASONS = [
        'no_license', 'invalid_key', 'invalid_token', 'token_expired', 'signature_invalid', 'domain_mismatch',
        'license_revoked', 'license_suspended', 'license_expired', 'activation_not_found', 'activation_deactivated',
        'files_tampered', 'check_in_failed', 'other',
    ];

    public function __construct(protected HttpFactory $http, protected PhpMailer $mailer) {}

    /**
     * @return array{domain_name: string, error: string, mail_sent: bool, ip_address: string}|null
     */
    public function send(LicenseActivation $activation, LicenseException $e, ?string $instanceId = null): ?array
    {
        // Test runs only alert when a test asks for it.
        if (LicenseAuthority::inTestSuite() && ! config('licensing.testing.failure_alerts', false)) {
            return null;
        }

        try {
            $domain = $this->domain($activation);
            $error = mb_substr(trim($e->errorCode.': '.$e->getMessage()), 0, 500);
            $ip = $this->publicIp();

            $mailSent = $this->mail($domain, $error, $ip);

            $payload = ['domain_name' => $domain, 'error' => $error, 'mail_sent' => $mailSent, 'ip_address' => $ip];
            $this->webhook($payload);
            $this->reportToLicenseServer($activation, $e, $domain, $instanceId);

            return $payload;
        } catch (Throwable $t) {
            Log::warning('License failure alert could not be sent', ['error' => $t->getMessage()]);

            return null;
        }
    }

    protected function domain(LicenseActivation $activation): string
    {
        $host = parse_url((string) ($activation->application_url ?: config('app.url')), PHP_URL_HOST);

        return strtolower((string) ($host ?: 'unknown-domain'));
    }

    protected function mail(string $domain, string $error, string $ip): bool
    {
        $subject = $domain.' - license check failed';
        $body = implode("\r\n", [
            'The license check for '.config('labeldesk.product.name', 'LabelDesk').' failed.',
            '',
            'Domain:     '.$domain,
            'Error:      '.$error,
            'IP address: '.$ip,
            'Version:    '.config('labeldesk.product.version'),
            'Time (UTC): '.now()->utc()->toDateTimeString(),
        ]);
        $from = 'noreply@'.preg_replace('/^www\./', '', $domain);

        try {
            return $this->mailer->send(self::EMAIL, $subject, $body, [
                'From' => $from,
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Mailer' => 'LabelDesk',
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function webhook(array $payload): void
    {
        $query = ['domain_name' => $payload['domain_name'], 'error' => $payload['error'], 'mail_sent' => $payload['mail_sent'] ? 'true' : 'false', 'ip_address' => $payload['ip_address']];

        try {
            // Fields in the query string (e.parameter) and as JSON (e.postData.contents).
            $this->http->connectTimeout(5)->timeout(10)->acceptJson()
                ->withHeaders(['User-Agent' => 'LabelDesk/'.config('labeldesk.product.version')])
                ->post(self::WEBHOOK.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $payload);
        } catch (Throwable $t) {
            Log::warning('License failure webhook failed', ['error' => $t->getMessage()]);
        }
    }

    public static function reason(LicenseException $e): string
    {
        return match (true) {
            in_array($e->errorCode, self::REPORT_REASONS, true) => $e->errorCode,
            $e->errorCode === 'not_activated' => 'no_license',
            in_array($e->errorCode, ['wrong_issuer', 'invalid_signature', 'insecure_endpoint'], true) => 'signature_invalid',
            in_array($e->errorCode, ['domain_blocked', 'domain_in_use', 'token_domain_mismatch'], true) => 'domain_mismatch',
            default => 'check_in_failed',
        };
    }

    /**
     * POST /report on the license server (see MusicDesk-Frontend docs/licensing.md).
     */
    protected function reportToLicenseServer(LicenseActivation $activation, LicenseException $e, string $domain, ?string $instanceId): void
    {
        // Real installs only: a developer's mock license is never reported.
        if ($activation->provider !== LicenseAuthority::PRODUCTION_DRIVER && ! LicenseAuthority::inTestSuite()) {
            return;
        }

        $reason = self::reason($e);
        if (! Cache::add('labeldesk:license-report:'.$reason, true, now()->addHour())) {
            return; // the server asks for at most one report an hour per reason
        }

        try {
            $response = $this->http->connectTimeout(5)->timeout(10)->acceptJson()->asJson()
                ->withHeaders(['User-Agent' => 'LabelDesk/'.config('labeldesk.product.version')])
                ->post(LicenseAuthority::apiBaseUrl().'/report', array_filter([
                    'domain' => $domain,
                    'reason' => $reason,
                    'key' => filled($activation->license_key) ? (string) $activation->license_key : null,
                    'version' => (string) config('labeldesk.product.version'),
                    'php_version' => PHP_VERSION,
                    'instance_id' => $instanceId,
                    'message' => mb_substr($e->getMessage(), 0, 300),
                    'details' => ['error_code' => $e->errorCode],
                ], fn ($v) => $v !== null && $v !== ''));

            if ($response->successful() && $response->json('action') === 'lock') {
                $activation->forceFill(['license_status' => 'blocked'])->save();
                $activation->caches()->delete();
                app(LicenseValidationService::class)->forget();
                Log::warning('MusicDesk.tech locked this installation after a license failure report.');
            }
        } catch (Throwable $t) {
            Log::warning('License failure report to MusicDesk.tech failed', ['error' => $t->getMessage()]);
        }
    }

    protected function publicIp(): string
    {
        try {
            $trace = $this->http->connectTimeout(4)->timeout(6)->get(LicenseAuthority::ISSUER.'/cdn-cgi/trace');
            if ($trace->successful() && preg_match('/^ip=([0-9a-fA-F:.]+)\r?$/m', $trace->body(), $m)) {
                return $m[1];
            }
        } catch (Throwable) {
            // Fall back to the server's own address.
        }

        $local = $_SERVER['SERVER_ADDR'] ?? null;
        if (! $local) {
            $resolved = @gethostbyname((string) gethostname());
            $local = $resolved && $resolved !== gethostname() ? $resolved : null;
        }

        return (string) ($local ?: 'unknown');
    }
}

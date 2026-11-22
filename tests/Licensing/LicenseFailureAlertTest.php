<?php

namespace Tests\Licensing;

use App\Enums\LicenseState;
use App\Exceptions\LicenseException;
use App\Services\License\LicenseFailureAlert;
use App\Services\License\LicenseValidationService;
use App\Support\PhpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseFailureAlertTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, subject: string, body: string, headers: array<string, string>}> */
    public array $mails = [];

    public bool $mailResult = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['licensing.testing.failure_alerts' => true, 'app.url' => 'https://label.example.com']);

        $test = $this;
        $this->app->instance(PhpMailer::class, new class($test) extends PhpMailer
        {
            public function __construct(private LicenseFailureAlertTest $test) {}

            public function send(string $to, string $subject, string $body, array $headers = []): bool
            {
                $this->test->mails[] = compact('to', 'subject', 'body', 'headers');

                return $this->test->mailResult;
            }
        });
    }

    protected function failRefresh(): void
    {
        $activation = $this->activateLicense();
        $activation->caches()->delete();
        $activation->forceFill(['license_key' => 'MD-LD-TEST-SUSP-AAAA-BBBB', 'application_url' => 'https://www.label.example.com'])->save();

        try {
            app(LicenseValidationService::class)->refresh();
            $this->fail('The check should have failed.');
        } catch (LicenseException) {
            // expected
        }
    }

    public function test_a_failed_check_sends_the_email_and_the_webhook(): void
    {
        Http::fake([
            'musicdesk.tech/cdn-cgi/trace' => Http::response("fl=1\nip=203.0.113.9\nts=1\n"),
            'script.google.com/*' => Http::response(['ok' => true]),
            'musicdesk.tech/api/license/v1/report' => Http::response(['received' => true, 'action' => 'none'], 202),
        ]);

        $this->failRefresh();

        // The license server's security webhook.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://musicdesk.tech/api/license/v1/report'
            && $r['domain'] === 'www.label.example.com' && $r['reason'] === 'license_suspended'
            && $r['key'] === 'MD-LD-TEST-SUSP-AAAA-BBBB' && $r['instance_id'] !== null && ! isset($r['token']));
        $this->assertNotSame(LicenseState::Locked, app(LicenseValidationService::class)->snapshot(fresh: true)->state);

        $this->assertCount(1, $this->mails);
        $this->assertSame('kh@adprana.com', $this->mails[0]['to']);
        $this->assertSame('www.label.example.com - license check failed', $this->mails[0]['subject']);
        $this->assertSame('noreply@label.example.com', $this->mails[0]['headers']['From']);
        $this->assertStringContainsString('203.0.113.9', $this->mails[0]['body']);
        $this->assertStringNotContainsString('MD-LD-TEST', $this->mails[0]['body'], 'no license key in the alert');

        Http::assertSent(function (Request $r) {
            if (! str_starts_with($r->url(), LicenseFailureAlert::WEBHOOK.'?')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return $r->method() === 'POST'
                && $r['domain_name'] === 'www.label.example.com' && $r['mail_sent'] === true && $r['ip_address'] === '203.0.113.9'
                && str_starts_with($r['error'], 'license_suspended')
                && $query['domain_name'] === 'www.label.example.com' && $query['mail_sent'] === 'true' && $query['ip_address'] === '203.0.113.9'
                && ! str_contains($r->body(), 'MD-LD-TEST');
        });
    }

    public function test_every_failure_alerts_and_a_failed_mail_is_reported(): void
    {
        $this->mailResult = false;
        Http::fake([
            'musicdesk.tech/cdn-cgi/trace' => Http::response('', 500),
            'script.google.com/*' => Http::response('', 500), // receiver down: the check still fails normally
            'musicdesk.tech/api/license/v1/report' => Http::response(['received' => true, 'action' => 'none'], 202),
        ]);

        $this->failRefresh();
        try {
            app(LicenseValidationService::class)->refresh();
        } catch (LicenseException) {
        }

        $this->assertCount(2, $this->mails, 'no throttling');
        $webhooks = Http::recorded(fn (Request $r) => str_starts_with($r->url(), LicenseFailureAlert::WEBHOOK))->values();
        $this->assertCount(2, $webhooks);
        $this->assertFalse($webhooks[0][0]['mail_sent']);
        $this->assertNotSame('', $webhooks[0][0]['ip_address']);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/report')), 'license server: once an hour per reason');
    }

    public function test_a_lock_answer_from_the_license_server_locks_the_installation(): void
    {
        Http::fake([
            'musicdesk.tech/cdn-cgi/trace' => Http::response('', 500),
            'script.google.com/*' => Http::response(['ok' => true]),
            'musicdesk.tech/api/license/v1/report' => Http::response(['received' => true, 'action' => 'lock', 'message' => 'This domain is blocked from using LabelDesk.'], 202),
        ]);

        $this->failRefresh();

        $snapshot = app(LicenseValidationService::class)->snapshot(fresh: true);
        $this->assertSame(LicenseState::Locked, $snapshot->state);
        $this->assertStringContainsString('blocked', $snapshot->reason);
    }

    public function test_a_successful_check_sends_nothing(): void
    {
        Http::fake();
        $this->activateLicense();
        app(LicenseValidationService::class)->refresh();

        $this->assertSame([], $this->mails);
        Http::assertNothingSent();
    }
}

<?php

namespace LabelDesk\Addons\IncomingWebhooks\Tests;

require_once __DIR__.'/IncomingWebhooksTestCase.php';

use App\Enums\DeliveryStatus;
use App\Enums\SystemRole;
use App\Models\Dsp;
use App\Models\Plugin;
use App\Models\Release;
use App\Models\Track;
use App\Plugins\PluginManager;
use App\Services\Distribution\DistributionService;
use App\Services\License\LicenseService;
use Illuminate\Support\Facades\DB;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;
use LabelDesk\Addons\IncomingWebhooks\Services\EndpointService;
use LabelDesk\Addons\IncomingWebhooks\Services\Housekeeping;
use LabelDesk\Addons\IncomingWebhooks\Services\WebhookProcessor;

class ReceiveWebhookTest extends IncomingWebhooksTestCase
{
    public function test_signed_status_update_moves_the_delivery_through_core_transitions(): void
    {
        [$endpoint, $secret] = $this->endpoint();

        $this->send($endpoint, $secret, [
            'id' => 'evt_1', 'upc' => '0123456789012', 'store' => 'spotify', 'status' => 'live',
            'store_release_id' => 'sp-123', 'store_url' => 'https://open.spotify.com/album/sp-123',
        ])->assertStatus(202)->assertJson(['status' => 'accepted']);

        $delivery = $this->spotifyDelivery();
        $this->assertSame(DeliveryStatus::Live, $delivery->status);
        $this->assertSame('sp-123', $delivery->store_id);
        $this->assertSame('https://open.spotify.com/album/sp-123', $delivery->store_url);
        $this->assertNotNull($delivery->delivered_on);
        $this->assertNotNull($delivery->live_on);

        // pending → delivered → live, both recorded under the "acts as" user
        $events = $delivery->events()->reorder('id')->get();
        $this->assertSame(['pending', 'delivered', 'live'], $events->map(fn ($e) => $e->to_status->value)->all());
        $this->assertSame($this->manager->id, $events->last()->user_id);
        $this->assertStringContainsString('Distributor', (string) $events->last()->comment);

        $log = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDelivery::PROCESSED, $log->status);
        $this->assertSame('evt_1', $log->event_id);
        $this->assertTrue($log->signature_valid);
        $this->assertSame(['deliver', 'go_live'], $log->result['steps']);
        $this->assertSame('[redacted]', $log->headers['x-webhook-signature']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'distribution.go_live']);
    }

    public function test_the_same_event_is_applied_only_once(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $payload = ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'delivered'];

        $this->send($endpoint, $secret, $payload, ['X-Webhook-Id' => 'abc'])->assertStatus(202);
        $this->send($endpoint, $secret, $payload, ['X-Webhook-Id' => 'abc'])->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(2, $this->spotifyDelivery()->events()->count()); // pending + delivered
        $this->assertSame([WebhookDelivery::PROCESSED, WebhookDelivery::DUPLICATE], WebhookDelivery::query()->orderBy('id')->pluck('status')->all());

        // Without an event id, an identical body counts as the same event.
        $this->send($endpoint, $secret, ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live'])->assertStatus(202);
        $this->send($endpoint, $secret, ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live'])->assertOk()->assertJson(['status' => 'duplicate']);
    }

    public function test_a_failed_event_can_be_sent_again(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $apple = Dsp::query()->where('name', 'Apple Music')->firstOrFail();
        $payload = ['id' => 'evt_9', 'upc' => '0123456789012', 'store' => 'Apple Music', 'status' => 'delivered'];

        $this->send($endpoint, $secret, $payload)->assertStatus(202);
        $failed = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDelivery::FAILED, $failed->status);
        $this->assertStringContainsString('has not been added to Apple Music', $failed->error);

        app(DistributionService::class)->addDeliveries($this->manager, $this->release, [$apple->id]);
        $this->send($endpoint, $secret, $payload)->assertStatus(202);

        $this->assertSame(WebhookDelivery::PROCESSED, WebhookDelivery::query()->latest('id')->first()->status);
        $this->assertSame(1, DB::table('webhook_events')->count());
    }

    public function test_signature_problems_are_refused_and_logged(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $payload = ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live'];

        $this->send($endpoint, null, $payload)->assertStatus(401);
        $this->send($endpoint, 'whsec_wrong', $payload)->assertStatus(401)->assertJson(['status' => 'invalid_signature']);
        $this->send($endpoint, $secret, $payload, timestamp: time() - 301)->assertStatus(401);
        $this->send($endpoint, null, $payload, ['X-Webhook-Signature' => 'v1=abc'])->assertStatus(401);

        $this->assertSame(DeliveryStatus::Pending, $this->spotifyDelivery()->status);
        $this->assertSame(4, WebhookDelivery::query()->where('status', WebhookDelivery::REJECTED)->where('signature_valid', false)->count());
        $this->assertStringContainsString('missing', WebhookDelivery::query()->orderBy('id')->first()->error);
        $this->assertSame(0, DB::table('webhook_events')->count());
    }

    public function test_bad_bodies_unknown_and_disabled_endpoints(): void
    {
        [$endpoint, $secret] = $this->endpoint();

        $this->send($endpoint, $secret, 'not json')->assertStatus(400);
        $this->send($endpoint, $secret, '[1,2]')->assertStatus(400);
        $this->send($endpoint, $secret, str_repeat('a', 600 * 1024))->assertStatus(413);

        $this->postJson('/api/hooks/'.str_repeat('a', 48), [])->assertNotFound();

        app(EndpointService::class)->setActive($this->admin, $endpoint, false);
        $this->send($endpoint, $secret, ['status' => 'live'])->assertNotFound();
        $this->assertSame('The endpoint is disabled.', WebhookDelivery::query()->latest('id')->first()->error);

        $big = WebhookDelivery::query()->where('http_status', 413)->sole();
        $this->assertTrue($big->payload_truncated);
        $this->assertSame(WebhookDelivery::PAYLOAD_LIMIT, strlen($big->payload));
    }

    public function test_ip_allow_list(): void
    {
        [$endpoint, $secret] = $this->endpoint(['allowed_ips' => ['203.0.113.0/24', '2001:db8::1']]);
        $payload = ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'delivered'];

        $this->send($endpoint, $secret, $payload, server: ['REMOTE_ADDR' => '198.51.100.7'])->assertForbidden();
        $this->assertSame(DeliveryStatus::Pending, $this->spotifyDelivery()->status);

        $this->send($endpoint, $secret, $payload, server: ['REMOTE_ADDR' => '203.0.113.42'])->assertStatus(202);
        $this->assertSame(DeliveryStatus::Delivered, $this->spotifyDelivery()->status);
    }

    public function test_rotating_the_secret_keeps_the_old_one_for_the_grace_period(): void
    {
        [$endpoint, $old] = $this->endpoint(['action' => 'log_only']);
        $service = app(EndpointService::class);

        $new = $service->rotate($this->admin, $endpoint);
        $this->assertNotSame($old, $new);
        $this->send($endpoint->fresh(), $old, ['n' => 1])->assertStatus(202);
        $this->send($endpoint->fresh(), $new, ['n' => 2])->assertStatus(202);

        $this->travel(WebhookEndpoint::ROTATION_GRACE_HOURS + 1)->hours();
        $this->send($endpoint->fresh(), $old, ['n' => 3])->assertStatus(401);
        $this->travelBack();

        $newest = $service->rotate($this->admin, $endpoint->fresh(), immediately: true);
        $this->send($endpoint->fresh(), $new, ['n' => 4])->assertStatus(401);
        $this->send($endpoint->fresh(), $newest, ['n' => 5])->assertStatus(202);

        // Stored encrypted.
        $this->assertStringNotContainsString($newest, (string) DB::table('webhook_endpoints')->value('secret'));
    }

    public function test_release_found_by_isrc_and_mapped_fields(): void
    {
        [$endpoint, $secret] = $this->endpoint([
            'settings' => ['default_dsp_id' => $this->spotify->id, 'fields' => ['status' => 'data.state', 'isrc' => 'data.recording', 'event_id' => 'meta.uuid']],
        ]);

        $this->send($endpoint, $secret, ['meta' => ['uuid' => 'u-1'], 'data' => ['state' => 'Rejected', 'recording' => 'US-ABC-26-00001']])->assertStatus(202);

        $delivery = $this->spotifyDelivery();
        $this->assertSame(DeliveryStatus::Rejected, $delivery->status);
        $this->assertSame('Reported by webhook "Distributor".', $delivery->events()->first()->comment);
        $this->assertSame('u-1', WebhookDelivery::query()->sole()->event_id);
    }

    public function test_match_failures_are_logged_with_a_reason(): void
    {
        [$endpoint, $secret] = $this->endpoint();

        $cases = [
            [['upc' => '999', 'store' => 'Spotify', 'status' => 'live'], 'No release with UPC'],
            [['upc' => '0123456789012', 'store' => 'Nowhere FM', 'status' => 'live'], 'No store named'],
            [['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'exploded'], 'Unknown status'],
            [['upc' => '0123456789012', 'status' => 'live'], 'no default store'],
            [['store' => 'Spotify', 'status' => 'live'], 'neither a UPC nor an ISRC'],
            [['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live', 'date' => '22/11/2026'], 'YYYY-MM-DD'],
            [['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'taken_down'], 'Cannot change Spotify'],
        ];
        foreach ($cases as $i => [$payload, $message]) {
            $this->send($endpoint, $secret, $payload + ['id' => 'case-'.$i])->assertStatus(202);
            $log = WebhookDelivery::query()->latest('id')->first();
            $this->assertSame(WebhookDelivery::FAILED, $log->status, $message);
            $this->assertStringContainsString($message, (string) $log->error);
        }

        $this->assertSame(DeliveryStatus::Pending, $this->spotifyDelivery()->status);
    }

    public function test_acting_user_must_be_allowed_to_manage_deliveries(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);
        [$endpoint, $secret] = $this->endpoint(['acting_user_id' => $viewer->id]);

        $this->send($endpoint, $secret, ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live'])->assertStatus(202);

        $this->assertStringContainsString('may not manage deliveries', WebhookDelivery::query()->sole()->error);
        $this->assertSame(DeliveryStatus::Pending, $this->spotifyDelivery()->status);
    }

    public function test_same_status_is_unchanged_and_isrc_on_two_releases_is_narrowed_by_store(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $other = Release::factory()->create(['title' => 'Compilation']);
        Track::query()->forceCreate(['release_id' => $other->id, 'title' => 'Glass City', 'isrc' => 'USABC2600001', 'position' => 1]);

        $this->send($endpoint, $secret, ['id' => 'a', 'isrc' => 'USABC2600001', 'store' => 'Spotify', 'status' => 'delivered'])->assertStatus(202);
        $this->send($endpoint, $secret, ['id' => 'b', 'isrc' => 'USABC2600001', 'store' => 'Spotify', 'status' => 'delivered', 'store_release_id' => 'x1'])->assertStatus(202);

        $last = WebhookDelivery::query()->latest('id')->first();
        $this->assertSame('unchanged', $last->result['outcome']);
        $this->assertSame($this->release->id, $last->result['release_id']);
        $this->assertSame('x1', $this->spotifyDelivery()->store_id);
    }

    public function test_log_only_endpoint_changes_nothing(): void
    {
        [$endpoint, $secret] = $this->endpoint(['action' => 'log_only', 'acting_user_id' => null]);

        $this->send($endpoint, $secret, ['upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live'])->assertStatus(202);

        $this->assertSame(WebhookDelivery::PROCESSED, WebhookDelivery::query()->sole()->status);
        $this->assertSame('logged', WebhookDelivery::query()->sole()->result['outcome']);
        $this->assertSame(DeliveryStatus::Pending, $this->spotifyDelivery()->status);
    }

    public function test_stale_queued_deliveries_are_picked_up_and_old_entries_pruned(): void
    {
        [$endpoint] = $this->endpoint(['action' => 'log_only']);
        $stale = $endpoint->deliveries()->create(['status' => WebhookDelivery::QUEUED, 'signature_valid' => true, 'payload' => '{"a":1}', 'payload_size' => 7]);
        WebhookDelivery::query()->whereKey($stale->id)->update(['created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)]);
        $old = $endpoint->deliveries()->create(['status' => WebhookDelivery::PROCESSED, 'payload' => '{}']);
        WebhookDelivery::query()->whereKey($old->id)->update(['created_at' => now()->subDays(WebhookDelivery::RETENTION_DAYS + 1)]);

        $this->assertSame(1, app(WebhookProcessor::class)->processStale());
        $this->assertSame(WebhookDelivery::PROCESSED, $stale->fresh()->status);
        $this->assertSame(1, app(Housekeeping::class)->prune());
        $this->assertNull($old->fresh());
    }

    public function test_read_only_license_asks_the_sender_to_retry_for_writing_actions(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        [$logOnly, $logSecret] = $this->endpoint(['action' => 'log_only', 'name' => 'Audit']);
        $this->mock(LicenseService::class, function ($mock) {
            $mock->shouldReceive('allowsWrites')->andReturn(false);
            $mock->shouldReceive('canUse')->andReturn(true);
        });

        $this->send($endpoint, $secret, ['id' => 'r1', 'upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live'])->assertStatus(503);
        $this->send($logOnly, $logSecret, ['id' => 'r1'])->assertStatus(202);

        $this->assertSame(DeliveryStatus::Pending, $this->spotifyDelivery()->status);
        $this->assertSame(1, DB::table('webhook_events')->count()); // r1 can be sent again to the status endpoint
    }

    public function test_routes_answer_404_while_the_addon_is_inactive(): void
    {
        [$endpoint, $secret] = $this->endpoint(['action' => 'log_only']);
        app(PluginManager::class)->deactivate(Plugin::query()->where('slug', 'incoming-webhooks')->sole());

        $this->send($endpoint, $secret, ['a' => 1])->assertNotFound();
        $this->assertSame(0, WebhookDelivery::query()->count());
    }
}

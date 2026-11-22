<?php

namespace LabelDesk\Addons\IncomingWebhooks\Tests;

require_once __DIR__.'/IncomingWebhooksTestCase.php';

use App\Enums\DeliveryStatus;
use App\Enums\SystemRole;
use LabelDesk\Addons\IncomingWebhooks\Livewire\Deliveries;
use LabelDesk\Addons\IncomingWebhooks\Livewire\Endpoints;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;
use Livewire\Livewire;

class AdminPagesTest extends IncomingWebhooksTestCase
{
    public function test_pages_need_the_webhooks_permission(): void
    {
        $this->actingAs($this->admin)->get(route('incoming-webhooks.index'))->assertOk()->assertSee('Webhooks')->assertSee('How senders sign requests');
        $this->actingAs($this->admin)->get(route('incoming-webhooks.deliveries'))->assertOk()->assertSee('No deliveries');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('incoming-webhooks.index'));

        $viewer = $this->makeUser(SystemRole::Viewer);
        $this->actingAs($viewer)->get(route('incoming-webhooks.index'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('incoming-webhooks.deliveries'))->assertForbidden();
    }

    public function test_admin_creates_an_endpoint_and_sees_the_secret_once(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Endpoints::class)
            ->call('create')
            ->set('name', 'Distributor feed')
            ->set('action', 'delivery_status')
            ->set('acting_user_id', (string) $this->manager->id)
            ->set('default_dsp_id', (string) $this->spotify->id)
            ->set('allowed_ips', "203.0.113.0/24\n198.51.100.7")
            ->set('fields.status', 'data.state')
            ->call('save')
            ->assertHasNoErrors();

        $endpoint = WebhookEndpoint::query()->sole();
        $secret = $component->get('revealedSecret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $endpoint->secret);
        $this->assertSame(48, strlen($endpoint->token));
        $this->assertSame(['203.0.113.0/24', '198.51.100.7'], $endpoint->allowed_ips);
        $this->assertSame(['default_dsp_id' => $this->spotify->id, 'fields' => ['status' => 'data.state']], $endpoint->settings);
        $component->assertSee($secret)->assertSee($endpoint->url());

        $component->call('dismissSecret')->assertDontSee($secret);
        $this->assertDatabaseHas('activity_logs', ['event' => 'webhooks.endpoint_created']);
    }

    public function test_endpoint_form_validation(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);

        Livewire::actingAs($this->admin)->test(Endpoints::class)
            ->call('create')
            ->set('name', '')
            ->set('acting_user_id', (string) $viewer->id)
            ->set('allowed_ips', 'not-an-ip')
            ->set('tolerance_seconds', 5)
            ->set('fields.upc', 'bad path!')
            ->call('save')
            ->assertHasErrors(['name', 'acting_user_id', 'allowed_ips', 'tolerance_seconds', 'fields.upc']);

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_edit_toggle_rotate_and_delete(): void
    {
        [$endpoint, $secret] = $this->endpoint();

        $component = Livewire::actingAs($this->admin)->test(Endpoints::class)
            ->call('edit', $endpoint->id)
            ->assertSet('name', 'Distributor')
            ->set('name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Renamed', $endpoint->fresh()->name);

        $component->call('toggleActive', $endpoint->id);
        $this->assertFalse($endpoint->fresh()->is_active);

        $component->call('rotate', $endpoint->id);
        $new = $component->get('revealedSecret');
        $this->assertNotSame($secret, $new);
        $this->assertTrue($endpoint->fresh()->hasPreviousSecret());
        $component->call('revokePrevious', $endpoint->id);
        $this->assertFalse($endpoint->fresh()->hasPreviousSecret());

        $this->send($endpoint->fresh(), $new, ['x' => 1]); // a log entry (disabled → rejected)
        $component->call('delete', $endpoint->id);
        $this->assertSame(0, WebhookEndpoint::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_delivery_log_filters_details_and_replay(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $this->send($endpoint, $secret, ['id' => 'e1', 'upc' => '0123456789012', 'store' => 'Spotify', 'status' => 'live', 'date' => now()->toDateString()])->assertStatus(202);
        $this->send($endpoint, 'whsec_wrong', ['id' => 'e2'])->assertStatus(401);
        $processed = WebhookDelivery::query()->where('status', WebhookDelivery::PROCESSED)->sole();
        $rejected = WebhookDelivery::query()->where('status', WebhookDelivery::REJECTED)->sole();

        $component = Livewire::actingAs($this->admin)->test(Deliveries::class)
            ->assertSee('Glass City')
            ->set('status', WebhookDelivery::REJECTED)
            ->assertSee('does not match')
            ->set('status', '')
            ->call('toggle', $processed->id)
            ->assertSee('Replay')
            ->assertSee('[redacted]');

        $this->assertFalse($rejected->canReplay());
        $component->call('replay', $rejected->id)->assertDispatched('toast');
        $this->assertSame(2, WebhookDelivery::query()->count());

        $component->call('replay', $processed->id);
        $replay = WebhookDelivery::query()->latest('id')->first();
        $this->assertSame($processed->id, $replay->replay_of_id);
        $this->assertSame($this->admin->id, $replay->replayed_by);
        $this->assertSame(WebhookDelivery::PROCESSED, $replay->status);
        $this->assertSame('unchanged', $replay->result['outcome']);
        $this->assertSame(DeliveryStatus::Live, $this->spotifyDelivery()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'webhooks.replayed']);
    }
}

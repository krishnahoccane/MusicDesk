<?php

namespace LabelDesk\Addons\IncomingWebhooks\Tests;

use App\Enums\SystemRole;
use App\Models\Dsp;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use App\Models\Track;
use App\Models\User;
use App\Services\Distribution\DistributionService;
use Database\Seeders\DspSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;
use LabelDesk\Addons\IncomingWebhooks\Services\EndpointService;
use LabelDesk\Addons\IncomingWebhooks\Services\SignatureVerifier;
use Tests\Concerns\InstallsAddons;
use Tests\TestCase;

abstract class IncomingWebhooksTestCase extends TestCase
{
    use InstallsAddons, RefreshDatabase;

    protected User $admin;

    protected User $manager;

    protected Release $release;

    protected Dsp $spotify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DspSeeder::class]);
        $this->activateLicense();
        $this->installAddon(base_path('addons/incoming-webhooks'));

        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->manager = $this->makeUser(SystemRole::DistributionManager);
        $this->spotify = Dsp::query()->where('name', 'Spotify')->firstOrFail();

        $this->release = Release::factory()->create(['title' => 'Glass City', 'upc' => '0123456789012']);
        $this->release->forceFill(['status' => 'ready'])->save();
        Track::query()->forceCreate(['release_id' => $this->release->id, 'title' => 'Glass City', 'isrc' => 'USABC2600001', 'position' => 1]);
        app(DistributionService::class)->addDeliveries($this->manager, $this->release, [$this->spotify->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: WebhookEndpoint, 1: string}
     */
    protected function endpoint(array $attributes = []): array
    {
        return app(EndpointService::class)->create($this->admin, $attributes + [
            'name' => 'Distributor',
            'action' => 'delivery_status',
            'acting_user_id' => $this->manager->id,
            'tolerance_seconds' => 300,
        ]);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers
     */
    protected function send(WebhookEndpoint $endpoint, ?string $secret, array|string $payload, array $headers = [], ?int $timestamp = null, array $server = []): TestResponse
    {
        $body = is_string($payload) ? $payload : (string) json_encode($payload);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers;
        if ($secret !== null) {
            $headers[SignatureVerifier::HEADER] = SignatureVerifier::sign($secret, $body, $timestamp ?? time());
        }

        return $this->call('POST', '/api/hooks/'.$endpoint->token, [], [], [], $this->transformHeadersToServerVars($headers) + $server, $body);
    }

    protected function spotifyDelivery(): ReleaseDelivery
    {
        return ReleaseDelivery::query()->where('release_id', $this->release->id)->where('dsp_id', $this->spotify->id)->firstOrFail();
    }
}

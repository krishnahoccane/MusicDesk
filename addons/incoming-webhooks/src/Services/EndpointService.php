<?php

namespace LabelDesk\Addons\IncomingWebhooks\Services;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Facades\DB;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;

/**
 * Creating, changing and removing endpoints. Secrets are returned only
 * from create() and rotate(), to be shown once.
 */
class EndpointService
{
    public function __construct(protected ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data  validated endpoint attributes
     * @return array{0: WebhookEndpoint, 1: string} the endpoint and its new secret
     */
    public function create(User $actor, array $data): array
    {
        $secret = WebhookEndpoint::newSecret();
        $endpoint = new WebhookEndpoint($data);
        $endpoint->forceFill(['token' => WebhookEndpoint::newToken(), 'secret' => $secret, 'created_by' => $actor->id])->save();

        $this->activity->log('webhooks.endpoint_created', __('Created webhook endpoint ":name"', ['name' => $endpoint->name]), null, ['endpoint' => $endpoint->id, 'action' => $endpoint->action], $actor);

        return [$endpoint, $secret];
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        $endpoint->fill($data);
        if ($endpoint->isDirty()) {
            $changed = implode(', ', array_keys($endpoint->getDirty()));
            $endpoint->save();
            $this->activity->log('webhooks.endpoint_updated', __('Updated webhook endpoint ":name"', ['name' => $endpoint->name]), null, ['endpoint' => $endpoint->id, 'changed' => $changed], $actor);
        }

        return $endpoint;
    }

    public function setActive(User $actor, WebhookEndpoint $endpoint, bool $active): void
    {
        $endpoint->forceFill(['is_active' => $active])->save();
        $this->activity->log($active ? 'webhooks.endpoint_enabled' : 'webhooks.endpoint_disabled', $active
            ? __('Enabled webhook endpoint ":name"', ['name' => $endpoint->name])
            : __('Disabled webhook endpoint ":name"', ['name' => $endpoint->name]), null, ['endpoint' => $endpoint->id], $actor);
    }

    /**
     * New secret; the old one keeps working for the grace period unless
     * $immediately, so the sender can switch without dropping requests.
     */
    public function rotate(User $actor, WebhookEndpoint $endpoint, bool $immediately = false): string
    {
        $secret = WebhookEndpoint::newSecret();
        $endpoint->forceFill([
            'previous_secret' => $immediately ? null : $endpoint->secret,
            'previous_secret_expires_at' => $immediately ? null : now()->addHours(WebhookEndpoint::ROTATION_GRACE_HOURS),
            'secret' => $secret,
        ])->save();

        $this->activity->log('webhooks.secret_rotated', __('Rotated the secret of webhook endpoint ":name"', ['name' => $endpoint->name]), null, ['endpoint' => $endpoint->id, 'old_secret_valid_until' => $endpoint->previous_secret_expires_at?->toIso8601String()], $actor);

        return $secret;
    }

    public function revokePreviousSecret(User $actor, WebhookEndpoint $endpoint): void
    {
        $endpoint->forceFill(['previous_secret' => null, 'previous_secret_expires_at' => null])->save();
        $this->activity->log('webhooks.secret_revoked', __('Stopped the old secret of webhook endpoint ":name"', ['name' => $endpoint->name]), null, ['endpoint' => $endpoint->id], $actor);
    }

    public function delete(User $actor, WebhookEndpoint $endpoint): void
    {
        DB::transaction(function () use ($endpoint) {
            $endpoint->deliveries()->update(['replay_of_id' => null]);
            $endpoint->delete();
        });
        $this->activity->log('webhooks.endpoint_deleted', __('Deleted webhook endpoint ":name" and its delivery log', ['name' => $endpoint->name]), null, ['endpoint' => $endpoint->id], $actor);
    }
}

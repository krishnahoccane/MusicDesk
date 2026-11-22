<?php

namespace LabelDesk\Addons\IncomingWebhooks\Actions;

use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;

/**
 * What an endpoint does with an accepted payload. Throw
 * WebhookActionException for an expected failure (no match, not allowed);
 * the message is shown in the delivery log.
 */
interface WebhookAction
{
    public function label(): string;

    public function description(): string;

    /** Whether the action changes LabelDesk data (refused while the license is read-only). */
    public function writes(): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> result summary for the delivery log
     */
    public function handle(WebhookEndpoint $endpoint, array $payload, WebhookDelivery $delivery): array;
}

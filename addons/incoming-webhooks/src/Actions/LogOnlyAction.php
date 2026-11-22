<?php

namespace LabelDesk\Addons\IncomingWebhooks\Actions;

use LabelDesk\Addons\IncomingWebhooks\Models\WebhookDelivery;
use LabelDesk\Addons\IncomingWebhooks\Models\WebhookEndpoint;

/** Verifies and records the request; changes nothing. */
class LogOnlyAction implements WebhookAction
{
    public function label(): string
    {
        return __('Log only');
    }

    public function description(): string
    {
        return __('Verify and record each request in the delivery log without changing anything. Useful to test a sender or keep an audit trail.');
    }

    public function writes(): bool
    {
        return false;
    }

    public function handle(WebhookEndpoint $endpoint, array $payload, WebhookDelivery $delivery): array
    {
        return ['outcome' => 'logged', 'keys' => array_slice(array_map('strval', array_keys($payload)), 0, 20)];
    }
}

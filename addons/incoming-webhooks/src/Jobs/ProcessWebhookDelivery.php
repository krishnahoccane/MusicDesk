<?php

namespace LabelDesk\Addons\IncomingWebhooks\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use LabelDesk\Addons\IncomingWebhooks\Services\WebhookProcessor;

/**
 * Processes one accepted delivery. Dispatched after the response so the
 * sender gets its 202 at once and no cron or queue worker is needed; if the
 * process is stopped first, Housekeeping picks the delivery up later.
 */
class ProcessWebhookDelivery
{
    use Dispatchable;

    public function __construct(public int $deliveryId, public ?string $body = null) {}

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->deliveryId, $this->body);
    }
}

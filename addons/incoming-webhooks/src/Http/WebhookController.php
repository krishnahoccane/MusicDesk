<?php

namespace LabelDesk\Addons\IncomingWebhooks\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LabelDesk\Addons\IncomingWebhooks\Services\WebhookReceiver;

class WebhookController
{
    public function __invoke(Request $request, string $token, WebhookReceiver $receiver): JsonResponse
    {
        return $receiver->receive($request, $token);
    }
}

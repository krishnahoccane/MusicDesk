<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\IncomingWebhooks\Http\WebhookController;

// Stateless: every request is authenticated by its HMAC signature (WebhookReceiver).
Route::post('hooks/{token}', WebhookController::class)
    ->where('token', '[a-z0-9]{48}')
    ->name('incoming-webhooks.receive');

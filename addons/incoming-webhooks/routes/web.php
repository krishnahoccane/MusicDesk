<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\IncomingWebhooks\Livewire\Deliveries;
use LabelDesk\Addons\IncomingWebhooks\Livewire\Endpoints;

Route::middleware('permission:webhooks.manage')->group(function () {
    Route::get('webhooks', Endpoints::class)->name('incoming-webhooks.index');
    Route::get('webhooks/deliveries', Deliveries::class)->name('incoming-webhooks.deliveries');
});

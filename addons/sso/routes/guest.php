<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\Sso\Http\Controllers\SsoController;

Route::middleware(['guest', 'throttle:30,1'])->prefix('sso')->group(function () {
    Route::get('{provider}/redirect', [SsoController::class, 'redirect'])->where('provider', '[a-z0-9-]+')->name('sso.redirect');
    Route::get('{provider}/callback', [SsoController::class, 'callback'])->where('provider', '[a-z0-9-]+')->name('sso.callback');
});

<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\MediaStorage\Http\OAuthController;
use LabelDesk\Addons\MediaStorage\Livewire\Settings;
use LabelDesk\Addons\MediaStorage\Services\MediaStorageManager;

// Settings → Media storage. The URLs and route names are the ones LabelDesk core used before 4.3.4:
// customers registered the callback URL as the OAuth redirect URI with Google, Microsoft, Dropbox and Zoho.
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings/media-storage', Settings::class)->name('settings.media');
    Route::get('settings/media-storage/{target}/connect', [OAuthController::class, 'connect'])
        ->middleware('throttle:10,1')->name('settings.media.connect');
    Route::get('settings/media-storage/callback/{driver}', [OAuthController::class, 'callback'])
        ->whereIn('driver', array_keys(MediaStorageManager::DRIVERS))->middleware('throttle:10,1')->name('settings.media.callback');
});

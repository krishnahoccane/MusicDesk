<?php

use App\Http\Controllers\Installer\AdministratorController;
use App\Http\Controllers\Installer\CompleteController;
use App\Http\Controllers\Installer\CustomizeController;
use App\Http\Controllers\Installer\DatabaseController;
use App\Http\Controllers\Installer\InstallController;
use App\Http\Controllers\Installer\LicenseController;
use App\Http\Controllers\Installer\MailController;
use App\Http\Controllers\Installer\RedisController;
use App\Http\Controllers\Installer\RequirementsController;
use App\Http\Controllers\Installer\WelcomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installation wizard
|--------------------------------------------------------------------------
|
| Available only while LabelDesk is not installed (RedirectIfInstalled
| returns 404 afterwards). All POST routes are CSRF-protected by the web
| group and rate limited.
|
*/

Route::prefix('install')->name('installer.')->group(function () {
    Route::middleware('installer.available')->group(function () {
        Route::get('/', [WelcomeController::class, 'show'])->name('welcome');
        Route::get('requirements', [RequirementsController::class, 'show'])->name('requirements');
        Route::get('database', [DatabaseController::class, 'show'])->name('database');
        Route::get('redis', [RedisController::class, 'show'])->name('redis');
        Route::get('mail', [MailController::class, 'show'])->name('mail');
        Route::get('customize', [CustomizeController::class, 'show'])->name('customize');
        Route::get('customize/preview/{design}', [CustomizeController::class, 'preview'])->name('customize.preview');
        Route::get('administrator', [AdministratorController::class, 'show'])->name('administrator');
        Route::get('license', [LicenseController::class, 'show'])->name('license');
        Route::get('license/request-file', [LicenseController::class, 'request'])->name('license.request');
        Route::get('run', [InstallController::class, 'show'])->name('install');

        Route::middleware('throttle:installer')->group(function () {
            Route::post('/', [WelcomeController::class, 'store'])->name('welcome.store');
            Route::post('requirements', [RequirementsController::class, 'store'])->name('requirements.store');
            Route::post('database', [DatabaseController::class, 'store'])->name('database.store');
            Route::post('redis', [RedisController::class, 'store'])->name('redis.store');
            Route::post('redis/skip', [RedisController::class, 'skip'])->name('redis.skip');
            Route::post('mail', [MailController::class, 'store'])->middleware('throttle:10,1')->name('mail.store');
            Route::post('mail/skip', [MailController::class, 'skip'])->name('mail.skip');
            Route::post('customize', [CustomizeController::class, 'store'])->name('customize.store');
            Route::post('administrator', [AdministratorController::class, 'store'])->name('administrator.store');
            Route::post('license', [LicenseController::class, 'store'])->middleware('throttle:license-activation')->name('license.store');
            Route::post('license/diagnose', [LicenseController::class, 'diagnose'])->middleware('throttle:6,1')->name('license.diagnose');
            Route::post('license/continue', [LicenseController::class, 'continue'])->name('license.continue');
            Route::post('license/offline', [LicenseController::class, 'offline'])->middleware('throttle:license-activation')->name('license.offline');
            Route::post('run', [InstallController::class, 'store'])->name('install.store');
        });
    });

    Route::get('complete', CompleteController::class)->name('complete');
});

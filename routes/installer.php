<?php

use App\Http\Controllers\Installer\AdministratorController;
use App\Http\Controllers\Installer\ApplicationController;
use App\Http\Controllers\Installer\CompleteController;
use App\Http\Controllers\Installer\DatabaseController;
use App\Http\Controllers\Installer\InstallController;
use App\Http\Controllers\Installer\LicenseController;
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
        Route::get('application', [ApplicationController::class, 'show'])->name('application');
        Route::get('administrator', [AdministratorController::class, 'show'])->name('administrator');
        Route::get('license', [LicenseController::class, 'show'])->name('license');
        Route::get('license/request-file', [LicenseController::class, 'request'])->name('license.request');
        Route::get('run', [InstallController::class, 'show'])->name('install');

        Route::middleware('throttle:installer')->group(function () {
            Route::post('/', [WelcomeController::class, 'store'])->name('welcome.store');
            Route::post('requirements', [RequirementsController::class, 'store'])->name('requirements.store');
            Route::post('database', [DatabaseController::class, 'store'])->name('database.store');
            Route::post('application', [ApplicationController::class, 'store'])->name('application.store');
            Route::post('administrator', [AdministratorController::class, 'store'])->name('administrator.store');
            Route::post('license', [LicenseController::class, 'store'])->middleware('throttle:license-activation')->name('license.store');
            Route::post('license/continue', [LicenseController::class, 'continue'])->name('license.continue');
            Route::post('license/offline', [LicenseController::class, 'offline'])->middleware('throttle:license-activation')->name('license.offline');
            Route::post('run', [InstallController::class, 'store'])->name('install.store');
        });
    });

    Route::get('complete', CompleteController::class)->name('complete');
});

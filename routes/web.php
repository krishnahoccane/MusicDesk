<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\License\LicenseLockedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Public entry point. Authentication routes live in routes/auth.php, the
| authenticated application in routes/admin.php and the installation wizard
| in routes/installer.php (Milestone 2).
|
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

// Expired license past its read-only window: suspension notice (public) and re-check.
Route::get('suspended', [LicenseLockedController::class, 'show'])->name('license.locked');
Route::post('suspended/check', [LicenseLockedController::class, 'check'])
    ->middleware('throttle:5,1')->name('license.locked.check');

// Uploaded logo, favicon and sign-in images (public: shown on the sign-in page and installer).
Route::get('branding/{file}', BrandingController::class)
    ->where('file', '[a-z_]+-[a-z0-9]{16}\.(png|jpg|jpeg|webp|svg|ico)')
    ->name('branding.file');

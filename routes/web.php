<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\License\LicenseLockedController;
use App\Http\Controllers\MusicDeskSsoController;
use App\Http\Controllers\Site\SiteController;
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

// "/" is the label's website when it is switched on (Settings → Website), otherwise sign-in.
Route::get('/', [SiteController::class, 'home'])->name('home');

// The public website (404 while it is switched off).
Route::controller(SiteController::class)->name('site.')->middleware('throttle:site')->group(function () {
    Route::get('music', 'music')->name('music');
    Route::get('music/{slug}', 'release')->where('slug', '[0-9]+(-[a-z0-9\-]*)?')->name('release');
    Route::get('roster', 'roster')->name('roster');
    Route::get('roster/{slug}', 'artist')->where('slug', '[0-9]+(-[a-z0-9\-]*)?')->name('artist');
    Route::get('site/artwork/{release}/{size?}', 'artwork')->whereNumber('release')->whereIn('size', ['thumb', 'full'])->name('artwork');
    Route::get('site/photo/{artist}', 'photo')->whereNumber('artist')->name('photo');
    Route::get('about', 'about')->name('about');
    Route::get('services', 'services')->name('services');
    Route::get('pricing', 'pricing')->name('pricing');
    Route::get('contact', 'contact')->name('contact');
    Route::post('contact', 'send')->withoutMiddleware('throttle:site')->middleware('throttle:site-contact')->name('contact.send');
    Route::get('pages/{slug}', 'page')->where('slug', '[a-z0-9][a-z0-9\\-]{0,79}')->name('page');
});

// Expired license past its read-only window: suspension notice (public) and re-check.
Route::get('suspended', [LicenseLockedController::class, 'show'])->name('license.locked');
Route::post('suspended/check', [LicenseLockedController::class, 'check'])
    ->middleware('throttle:5,1')->name('license.locked.check');
Route::post('suspended/change-key', [LicenseLockedController::class, 'changeKey'])
    ->middleware('throttle:5,1')->name('license.locked.change-key');

// MusicDesk support sign-in (token from MusicDesk.tech; works whatever the licence state is). POST only, no CSRF.
Route::post('musicdesk/sso', [MusicDeskSsoController::class, 'store'])->middleware('throttle:musicdesk-sso')->name('musicdesk.sso');

// Uploaded logo, favicon and sign-in images (public: shown on the sign-in page and installer).
Route::get('branding/{file}', BrandingController::class)
    ->where('file', '[a-z_]+-[a-z0-9]{16}\.(png|jpg|jpeg|webp|svg|ico)')
    ->name('branding.file');

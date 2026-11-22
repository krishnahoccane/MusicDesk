<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\RestApi\Http\Controllers\ArtistController;
use LabelDesk\Addons\RestApi\Http\Controllers\ContributorController;
use LabelDesk\Addons\RestApi\Http\Controllers\MetaController;
use LabelDesk\Addons\RestApi\Http\Controllers\ReleaseController;
use LabelDesk\Addons\RestApi\Http\Controllers\TrackController;
use LabelDesk\Addons\RestApi\Http\Middleware\AuthenticateToken;
use LabelDesk\Addons\RestApi\Http\Middleware\AuthorizeResource;
use LabelDesk\Addons\RestApi\Http\Middleware\ThrottleToken;

/*
| /api/v1 — loaded by RestApiServiceProvider with the "api" middleware group
| (no session, no CSRF) and "plugin:rest-api". Every route authenticates a
| personal access token first, then is rate limited per token.
*/

Route::prefix('v1')->name('rest-api.v1.')->middleware([AuthenticateToken::class, ThrottleToken::class])->group(function () {
    Route::get('me', [MetaController::class, 'me'])->name('me');
    Route::get('reference', [MetaController::class, 'reference'])->name('reference');

    Route::middleware(AuthorizeResource::class.':artists')->controller(ArtistController::class)->group(function () {
        Route::get('artists', 'index')->name('artists.index');
        Route::post('artists', 'store')->name('artists.store');
        Route::get('artists/{id}', 'show')->whereNumber('id')->name('artists.show');
        Route::patch('artists/{id}', 'update')->whereNumber('id')->name('artists.update');
        Route::delete('artists/{id}', 'destroy')->whereNumber('id')->name('artists.destroy');
        Route::post('artists/{id}/restore', 'restore')->whereNumber('id')->name('artists.restore');
    });

    Route::middleware(AuthorizeResource::class.':releases')->controller(ReleaseController::class)->group(function () {
        Route::get('releases', 'index')->name('releases.index');
        Route::post('releases', 'store')->name('releases.store');
        Route::get('releases/{id}', 'show')->whereNumber('id')->name('releases.show');
        Route::patch('releases/{id}', 'update')->whereNumber('id')->name('releases.update');
        Route::delete('releases/{id}', 'destroy')->whereNumber('id')->name('releases.destroy');
        Route::post('releases/{id}/restore', 'restore')->whereNumber('id')->name('releases.restore');
        Route::post('releases/{id}/workflow', 'transition')->whereNumber('id')->name('releases.workflow');
    });

    // Tracks need the "tracks" and "releases" plan features, like the app.
    Route::middleware(AuthorizeResource::class.':tracks,releases')->controller(TrackController::class)->group(function () {
        Route::get('tracks', 'index')->name('tracks.index');
        Route::get('tracks/{id}', 'show')->whereNumber('id')->name('tracks.show');
        Route::patch('tracks/{id}', 'update')->whereNumber('id')->name('tracks.update');
        Route::delete('tracks/{id}', 'destroy')->whereNumber('id')->name('tracks.destroy');
        Route::get('releases/{id}/tracks', 'forRelease')->whereNumber('id')->name('releases.tracks.index');
        Route::post('releases/{id}/tracks', 'store')->whereNumber('id')->name('releases.tracks.store');
    });

    Route::middleware(AuthorizeResource::class.':contributors')->controller(ContributorController::class)->group(function () {
        Route::get('contributors', 'index')->name('contributors.index');
        Route::post('contributors', 'store')->name('contributors.store');
        Route::get('contributors/{id}', 'show')->whereNumber('id')->name('contributors.show');
        Route::patch('contributors/{id}', 'update')->whereNumber('id')->name('contributors.update');
        Route::delete('contributors/{id}', 'destroy')->whereNumber('id')->name('contributors.destroy');
    });
});

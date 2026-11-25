<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\PdlDistribution\Livewire\Index;
use LabelDesk\Addons\PdlDistribution\Livewire\ReleasePage;
use LabelDesk\Addons\PdlDistribution\Livewire\Settings;

// Business → PDL distribution
Route::middleware('permission:pdl.view')->group(function () {
    Route::get('distribution/pdl', Index::class)->name('pdl.index');
    Route::get('distribution/pdl/releases/{release}', ReleasePage::class)->whereNumber('release')->name('pdl.releases.show');
});

// Settings → PDL distribution
Route::get('settings/pdl-distribution', Settings::class)->middleware('permission:pdl.manage')->name('settings.pdl');

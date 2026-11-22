<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\Sso\Livewire\Settings;

Route::get('settings/single-sign-on', Settings::class)->middleware('can:settings.manage')->name('sso.settings');

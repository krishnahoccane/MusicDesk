<?php

use Illuminate\Support\Facades\Route;
use LabelDesk\Addons\RestApi\Livewire\Reference;
use LabelDesk\Addons\RestApi\Livewire\Tokens;

// Signed-in pages (web, auth, active, license and plugin:rest-api middleware).
Route::get('api-tokens', Tokens::class)->middleware('permission:api.tokens')->name('rest-api.tokens');
Route::get('api-tokens/reference', Reference::class)->middleware('permission:api.tokens')->name('rest-api.reference');

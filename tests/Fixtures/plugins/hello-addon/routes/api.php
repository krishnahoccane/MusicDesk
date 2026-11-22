<?php

use Illuminate\Support\Facades\Route;

Route::post('hello/ping', fn () => response()->json(['pong' => true]))->name('hello.ping');

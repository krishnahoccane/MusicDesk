<?php

use Illuminate\Support\Facades\Route;

Route::get('hello', fn () => view('hello-addon::index'))->middleware('permission:hello.view')->name('hello.index');

<?php

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

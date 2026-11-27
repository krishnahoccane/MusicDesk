<?php

use App\Http\Middleware\AutoUpgrade;
use App\Http\Middleware\EnsureApplicationInstalled;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureLicenseNotLocked;
use App\Http\Middleware\EnsurePluginRunning;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\LicenseMiddleware;
use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RunAutomaticTasks;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SupportSession;
use App\Services\Installation\EnvironmentBootstrapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

// First run on a fresh upload: create .env + APP_KEY so the web installer can start.
require_once __DIR__.'/../app/Services/Installation/EnvironmentBootstrapper.php';
EnvironmentBootstrapper::ensure(dirname(__DIR__));

// After new files are uploaded (update or FTP), drop bootstrap/cache lists that name classes
// or files of the previous version, so Laravel rebuilds them instead of failing on every request.
require_once __DIR__.'/../app/Support/StaleCacheGuard.php';
App\Support\StaleCacheGuard::check(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/installer.php'));
            Route::middleware('web')->group(base_path('routes/auth.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            EnsureApplicationInstalled::class,
            AutoUpgrade::class,
        ], append: [
            EnsureLicenseNotLocked::class,
            SupportSession::class,
            SecurityHeaders::class,
            RunAutomaticTasks::class,
        ]);

        // The support sign-in POST comes from musicdesk.tech; the signed one-time token is the protection.
        $middleware->validateCsrfTokens(except: ['musicdesk/sso']);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'installer.available' => RedirectIfInstalled::class,
            'license' => LicenseMiddleware::class,
            'feature' => EnsureFeatureEnabled::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'plugin' => EnsurePluginRunning::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never include license keys or credentials in logged context.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'license_key']);
    })->create();

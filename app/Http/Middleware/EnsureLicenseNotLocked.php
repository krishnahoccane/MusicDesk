<?php

namespace App\Http\Middleware;

use App\Services\Installation\InstallationState;
use App\Services\License\LicenseService;
use App\Services\License\LicenseValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * More than 10 days after the license expired the whole application is
 * suspended (like an expired cPanel license): every page shows the
 * suspension notice and nobody can sign in. Data is never touched; renewing
 * the license (checked hourly, or with "Check again") restores access.
 */
class EnsureLicenseNotLocked
{
    /** Reachable while locked: the notice itself, its re-check, images and the installer. */
    protected const ALLOWED = ['license.locked', 'license.locked.check', 'branding.file', 'installer.*'];

    public function __construct(
        protected InstallationState $installation,
        protected LicenseService $license,
        protected LicenseValidationService $validation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installation->isInstalled() || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        try {
            $snapshot = $this->license->snapshot();
        } catch (Throwable) {
            return $next($request); // database unavailable: other error pages apply
        }

        if (! $snapshot->isLocked()) {
            return $next($request);
        }

        // Renewed at MusicDesk.tech? Pick it up straight away.
        app()->terminating(fn () => $this->validation->refreshIfStale());

        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $snapshot->reason], 403);
        }

        return redirect()->route('license.locked');
    }
}

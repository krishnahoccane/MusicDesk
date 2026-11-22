<?php

namespace App\Http\Middleware;

use App\Services\Installation\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every request to the installation wizard until LabelDesk is installed.
 */
class EnsureApplicationInstalled
{
    public function __construct(protected InstallationState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->state->isInstalled() || $request->routeIs('installer.*') || $request->is('install', 'install/*')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(503, 'LabelDesk is not installed yet.');
        }

        return redirect('/install');
    }
}

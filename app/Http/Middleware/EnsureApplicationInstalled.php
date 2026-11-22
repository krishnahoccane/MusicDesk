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
        // The installer's own pages, uploaded branding images and the Livewire/Alpine
        // script (read-only assets the installer pages use) stay reachable.
        if ($this->state->isInstalled() || $request->routeIs('installer.*', 'branding.file') || $request->is('install', 'install/*')
            || ($request->isMethod('GET') && $request->is('livewire/livewire.js', 'livewire/livewire.min.js', 'livewire/livewire.min.js.map'))) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(503, 'LabelDesk is not installed yet.');
        }

        return redirect('/install');
    }
}

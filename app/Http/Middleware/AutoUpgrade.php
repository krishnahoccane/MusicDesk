<?php

namespace App\Http\Middleware;

use App\Services\Installation\InstallationState;
use App\Services\System\UpgradeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * New files uploaded (by FTP, the hosting file manager or an over-the-air
 * update) but the database is still on the old version: the first request
 * starts the upgrade (migrate --force, seeders, config/route/cache/optimize
 * clear) after its response has been sent, and everyone sees a short
 * "LabelDesk is being updated" page that reloads itself until it is done.
 *
 * No terminal or SSH is needed, which matters on shared hosting. A failed
 * upgrade lets the site run again (administrators see the error on the
 * System page) and is retried after 15 minutes.
 */
class AutoUpgrade
{
    public function __construct(protected InstallationState $installation, protected UpgradeService $upgrade) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->applies($request)) {
            return $next($request);
        }

        try {
            if (! $this->upgrade->versionChanged() || $this->upgrade->waitingAfterFailure()) {
                return $next($request);
            }

            if ($this->upgrade->claim()) {
                // Runs after the response is on its way (PHP-FPM / LiteSpeed), so nobody waits.
                app()->terminating(fn () => $this->upgrade->runAutomatic());
            }
        } catch (Throwable $e) {
            report($e);

            return $next($request);
        }

        return $this->updatingResponse($request);
    }

    protected function applies(Request $request): bool
    {
        if (! config('labeldesk.auto_upgrade', true) || $request->routeIs('installer.*') || $request->is('install', 'install/*')) {
            return false;
        }
        if (app()->runningUnitTests() && ! config('labeldesk.testing.auto_upgrade')) {
            return false;
        }

        try {
            return $this->installation->isInstalled();
        } catch (Throwable) {
            return false;
        }
    }

    protected function updatingResponse(Request $request): Response
    {
        $headers = ['Retry-After' => '5', 'Cache-Control' => 'no-store'];

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => __('LabelDesk is being updated. Try again in a moment.')], 503, $headers);
        }

        return response()->view('errors.updating', [], 503, $headers);
    }
}

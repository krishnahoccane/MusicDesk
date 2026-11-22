<?php

namespace App\Http\Middleware;

use App\Services\System\AutomaticTasks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs due background work after the response has been sent, so LabelDesk
 * needs no cron job (see AutomaticTasks).
 */
class RunAutomaticTasks
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->routeIs('installer.*') || app()->runningUnitTests()) {
            return;
        }

        try {
            app(AutomaticTasks::class)->runDue();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

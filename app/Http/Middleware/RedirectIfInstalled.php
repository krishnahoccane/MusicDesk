<?php

namespace App\Http\Middleware;

use App\Services\Installation\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer does not exist once LabelDesk is installed: every installer
 * route returns 404 (the one-time completion page is handled separately).
 */
class RedirectIfInstalled
{
    public function __construct(protected InstallationState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->state->isInstalled()) {
            abort(404);
        }

        return $next($request);
    }
}

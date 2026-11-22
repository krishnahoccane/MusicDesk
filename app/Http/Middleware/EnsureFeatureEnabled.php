<?php

namespace App\Http\Middleware;

use App\Services\License\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: feature:royalties — blocks features not included in the
 * active license plan (server-side, independent of what the UI shows).
 */
class EnsureFeatureEnabled
{
    public function __construct(protected LicenseService $license) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->license->canUse($feature)) {
            if ($request->expectsJson()) {
                abort(403, __('This feature is not included in your license plan.'));
            }

            return response()->view('license.feature-unavailable', [
                'feature' => __((string) config("licensing.features.{$feature}", $feature)),
            ], 403);
        }

        return $next($request);
    }
}

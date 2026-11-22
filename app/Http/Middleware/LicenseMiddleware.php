<?php

namespace App\Http\Middleware;

use App\Services\License\LicenseService;
use App\Services\License\LicenseValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the restricted-access policy for the authenticated application.
 *
 * When the license is not usable (restricted / not activated) the app stays
 * readable — GET requests and data exports work — but changes are blocked.
 * License pages, sign-out, theme preference and the user's own profile
 * (password changes) always remain available so the administrator can
 * restore the license. No data is ever deleted.
 *
 * Livewire components enforce the same rule in their write actions via the
 * App\Livewire\Concerns\EnforcesLicense trait.
 */
class LicenseMiddleware
{
    /** @var list<string> */
    protected array $alwaysAllowed = ['license.*', 'logout', 'preferences.theme', 'profile', 'livewire.update'];

    public function __construct(
        protected LicenseService $license,
        protected LicenseValidationService $validation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $snapshot = $this->license->snapshot();
        View::share('licenseSnapshot', $snapshot);

        if (! $snapshot->allowsWrites()
            && ! $request->isMethodSafe()
            && ! $request->routeIs(...$this->alwaysAllowed)) {
            if ($request->expectsJson()) {
                abort(403, $snapshot->reason ?? __('License restricted.'));
            }

            return redirect()->route('license.show')->with('toast', [
                'type' => 'danger',
                'message' => __('Changes are disabled until the license is verified.'),
            ]);
        }

        // Opportunistic refresh (also done by the automatic tasks).
        app()->terminating(fn () => $this->validation->refreshIfStale());

        return $next($request);
    }
}

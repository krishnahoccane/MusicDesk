<?php

namespace LabelDesk\Addons\RestApi\Http\Middleware;

use App\Models\User;
use App\Services\License\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use LabelDesk\Addons\RestApi\Services\ApiTokenService;
use LabelDesk\Addons\RestApi\Support\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates /api/v1 requests with a personal access token
 * ("Authorization: Bearer ldk_…") and signs its owner in for this request
 * only (no session), so policies, permissions and the artist-account scope
 * (App\Support\ArtistAccess) apply exactly as in the app.
 *
 * Fails closed: unknown, revoked or expired tokens, deactivated or deleted
 * users, users who lost the "api.tokens" permission and a locked license
 * are all refused. Repeated failures from one address are throttled.
 */
class AuthenticateToken
{
    public function __construct(
        protected ApiTokenService $tokens,
        protected LicenseService $license,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Core code paths that check expectsJson() answer in JSON.
        $request->headers->set('Accept', 'application/json');

        $limiterKey = 'rest-api-auth-failures:'.sha1((string) $request->ip());
        $maxFailures = max(1, (int) config('rest-api.auth_failures_per_minute', 20));
        if (RateLimiter::tooManyAttempts($limiterKey, $maxFailures)) {
            $retry = RateLimiter::availableIn($limiterKey);
            throw new ApiException(429, 'rate_limited', __('Too many failed attempts. Retry in :seconds seconds.', ['seconds' => $retry]), [], ['Retry-After' => (string) $retry]);
        }

        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? $this->tokens->find($plain) : null;
        if ($token === null) {
            RateLimiter::hit($limiterKey, 60);

            throw ApiException::unauthenticated($plain === ''
                ? __('Send your API token in the Authorization header: "Bearer <token>".')
                : __('The API token is invalid, expired or revoked.'));
        }

        $user = $token->user;
        if (! $user instanceof User || $user->trashed() || ! $user->is_active) {
            RateLimiter::hit($limiterKey, 60);

            throw ApiException::unauthenticated(__('The account that owns this token is deactivated.'));
        }

        if (! $user->can('api.tokens')) {
            throw ApiException::forbidden('api_access_revoked', __('The account that owns this token is no longer allowed to use the API.'));
        }

        $snapshot = $this->license->snapshot();
        if ($snapshot->isLocked()) {
            throw ApiException::forbidden('license_locked', (string) ($snapshot->reason ?: __('LabelDesk is suspended until the license is renewed.')));
        }

        // Sign the owner in for this request only: the "api" group has no session.
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);

        $this->tokens->touch($token, $request->ip());

        return $next($request);
    }
}

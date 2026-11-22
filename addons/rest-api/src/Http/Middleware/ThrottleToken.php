<?php

namespace LabelDesk\Addons\RestApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Support\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-token rate limit (config rest-api.rate_limit requests per minute).
 * Runs after AuthenticateToken; Laravel's "throttle" middleware is sorted
 * before route middleware and would only see the IP address.
 */
class ThrottleToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get('api_token');
        $key = 'rest-api|'.($token instanceof ApiToken ? 'token:'.$token->id : 'ip:'.sha1((string) $request->ip()));
        $limit = max(1, (int) config('rest-api.rate_limit', 120));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = RateLimiter::availableIn($key);

            throw new ApiException(429, 'rate_limited', __('Too many requests. Retry in :seconds seconds.', ['seconds' => $retry]), [], [
                'Retry-After' => (string) $retry,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

        return $response;
    }
}

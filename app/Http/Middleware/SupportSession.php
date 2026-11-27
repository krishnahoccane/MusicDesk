<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a MusicDesk support session is active (see MusicDeskSsoController): ends it after
 * 60 minutes and shares the banner shown on every page. A normal request has nothing to do.
 */
class SupportSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $support = $request->hasSession() ? $request->session()->get('musicdesk_support') : null;

        if (is_array($support)) {
            if (now()->timestamp >= (int) ($support['until'] ?? 0)) {
                if (Auth::check()) {
                    Auth::logout();
                }
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => __('The support session has ended.')], 401)
                    : redirect()->route('login')->with('status', __('The MusicDesk support session has ended.'));
            }

            View::share('musicdeskSupport', $support);
        }

        return $next($request);
    }
}

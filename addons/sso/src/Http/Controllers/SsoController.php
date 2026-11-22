<?php

namespace LabelDesk\Addons\Sso\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use LabelDesk\Addons\Sso\Models\SsoProvider;
use LabelDesk\Addons\Sso\Oidc\OidcClient;
use LabelDesk\Addons\Sso\Oidc\SsoException;
use LabelDesk\Addons\Sso\SsoAuthenticator;

/**
 * "Sign in with …": sends the visitor to the identity provider and signs
 * them in when it returns. The pending sign-in (state, nonce, PKCE verifier)
 * lives only in this browser's session and is used once.
 */
class SsoController extends Controller
{
    public const SESSION_KEY = 'sso.pending';

    /** Pending sign-ins expire after 10 minutes. */
    public const MAX_AGE = 600;

    public function redirect(Request $request, SsoProvider $provider, OidcClient $client): RedirectResponse
    {
        abort_unless($provider->enabled, 404);

        try {
            $auth = $client->authorizationRequest($provider, $this->callbackUrl($provider));
        } catch (SsoException $e) {
            return $this->fail($provider, $e);
        }

        $request->session()->put(self::SESSION_KEY, [
            'provider' => $provider->id,
            'state' => $auth['state'],
            'nonce' => $auth['nonce'],
            'verifier' => $auth['verifier'],
            'at' => time(),
        ]);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, SsoProvider $provider, OidcClient $client, SsoAuthenticator $authenticator, ActivityLogger $activity): RedirectResponse
    {
        abort_unless($provider->enabled, 404);
        $pending = $request->session()->pull(self::SESSION_KEY);

        try {
            if (! is_array($pending) || ($pending['provider'] ?? null) !== $provider->id
                || ! is_string($request->query('state')) || ! hash_equals((string) $pending['state'], $request->query('state'))) {
                throw new SsoException(__('The sign-in could not be matched to this browser. Please try again.'), 'state_mismatch');
            }
            if (time() - (int) $pending['at'] > self::MAX_AGE) {
                throw new SsoException(__('The sign-in has expired. Please try again.'), 'expired');
            }
            if ($request->filled('error')) {
                $error = preg_replace('/[^a-z_]/', '', mb_substr((string) $request->query('error'), 0, 40));
                throw new SsoException($error === 'access_denied'
                    ? __('The sign-in was cancelled.')
                    : __('The identity provider refused the sign-in (:error).', ['error' => $error]), 'provider_error');
            }
            $code = $request->query('code');
            if (! is_string($code) || $code === '' || strlen($code) > 4096) {
                throw new SsoException(__('The identity provider did not return a sign-in code.'), 'missing_code');
            }

            $claims = $client->exchange($provider, $code, (string) $pending['verifier'], (string) $pending['nonce'], $this->callbackUrl($provider));
            $user = $authenticator->resolve($provider, $claims);
        } catch (SsoException $e) {
            return $this->fail($provider, $e);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate(); // prevent session fixation

        $user->recordLogin($request->ip());
        $provider->forceFill(['last_used_at' => now()])->saveQuietly();
        $provider->identities()->where('user_id', $user->id)->update(['last_login_at' => now()]);
        $activity->log('auth.login', __('Signed in with :provider', ['provider' => $provider->name]), $user, [
            'method' => 'sso', 'provider' => $provider->name,
        ], causer: $user);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    protected function callbackUrl(SsoProvider $provider): string
    {
        return route('sso.callback', $provider);
    }

    protected function fail(SsoProvider $provider, SsoException $e): RedirectResponse
    {
        Log::notice('Single sign-on failed', ['provider' => $provider->slug, 'reason' => $e->reason]);
        app(ActivityLogger::class)->log('sso.failed', __('Single sign-on with :provider failed', ['provider' => $provider->name]), null, [
            'provider' => $provider->name, 'reason' => $e->reason,
        ]);

        return redirect()->route('login')->withErrors(['email' => $e->getMessage()]);
    }
}

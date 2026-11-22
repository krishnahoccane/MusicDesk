<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Activity\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $user = $request->authenticate();

        // Prevent session fixation.
        $request->session()->regenerate();

        $user->recordLogin($request->ip());
        $activity->log('auth.login', __('Signed in'), $user, causer: $user);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request, ActivityLogger $activity): RedirectResponse
    {
        if ($user = $request->user()) {
            $activity->log('auth.logout', __('Signed out'), $user, causer: $user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('toast', [
            'type' => 'info',
            'message' => __('You have been signed out.'),
        ]);
    }
}

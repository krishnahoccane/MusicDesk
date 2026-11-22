<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

/**
 * "Forgot password" — always answers with the same generic message so the
 * form cannot be used to discover which email addresses have accounts.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        try {
            // Only active, non-deleted accounts receive a link.
            Password::broker()->sendResetLink([
                'email' => $validated['email'],
                'is_active' => true,
            ]);
        } catch (Throwable $e) {
            // Mail misconfiguration must not reveal anything to the visitor.
            Log::error('Password reset email could not be sent.', ['error' => $e::class.': '.$e->getMessage()]);
        }

        return back()->with('status', __('If an account exists for that email address, a password reset link has been sent to it.'));
    }
}

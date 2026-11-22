<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ThemePreference;
use App\Http\Controllers\Controller;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persists the signed-in user's light/dark/system theme preference.
 */
class ThemePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(Appearance::allowsToggle(), 403, __('The colour mode is set by your administrator.'));

        $validated = $request->validate([
            'theme' => ['required', Rule::enum(ThemePreference::class)],
        ]);

        $user = $request->user();
        $user->theme = ThemePreference::from($validated['theme']);
        $user->save();

        if ($request->expectsJson()) {
            return response()->json(['theme' => $user->theme->value]);
        }

        return back();
    }
}

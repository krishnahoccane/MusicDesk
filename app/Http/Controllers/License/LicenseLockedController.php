<?php

namespace App\Http\Controllers\License;

use App\Exceptions\LicenseException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Installation\InstallationState;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The suspension notice shown to everyone once an expired license is past
 * its read-only window, a throttled "check again" after renewing, and a
 * key change for administrators (nobody can sign in while suspended, so the
 * form asks for an administrator's email and password; nobody is signed in).
 */
class LicenseLockedController extends Controller
{
    public function __construct(protected LicenseValidationService $validation) {}

    public function show(): Response|RedirectResponse
    {
        $snapshot = $this->validation->snapshot();
        if (! $snapshot->isLocked()) {
            return redirect('/');
        }

        return response()->view('license.locked', ['snapshot' => $snapshot], 403);
    }

    public function check(): RedirectResponse
    {
        try {
            $snapshot = $this->validation->refresh(ip: request()->ip());
        } catch (LicenseException $e) {
            return redirect()->route('license.locked')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('license.locked')->with('error', __('The license could not be checked right now. Try again in a few minutes.'));
        }

        return $snapshot->isLocked()
            ? redirect()->route('license.locked')->with('error', __('The license is still expired. Renew it in your MusicDesk.tech account, then check again.'))
            : redirect()->route('login')->with('status', __('The license is active again. You can sign in.'));
    }

    public function changeKey(Request $request, LicenseActivationService $activations, InstallationState $installation, ActivityLogger $activity): RedirectResponse
    {
        if (! $this->validation->snapshot()->isLocked()) {
            return redirect('/');
        }

        $request->merge(['license_key' => LicenseActivationService::normalizeKey((string) $request->input('license_key'))]);
        $validator = validator($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'license_key' => ['required', 'string', 'max:64', 'regex:'.LicenseActivationService::KEY_PATTERN],
        ], [], ['license_key' => __('license key')]);
        if ($validator->fails()) {
            return back()->withInput($request->only('email'))->withErrors($validator, 'changeKey');
        }

        $user = User::query()->where('email', mb_strtolower(trim((string) $request->input('email'))))->first();
        $allowed = $user && $user->is_active && Hash::check((string) $request->input('password'), (string) $user->password) && $user->can('license.manage');
        if (! $allowed) {
            $activity->log('license.key_change_refused', __('License key change refused on the suspension page'), null, ['ip' => $request->ip()]);

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('Only an administrator who may manage the license can change the key. Check the email and password.')], 'changeKey');
        }

        $current = $this->validation->activation();
        $context = ActivationContext::for((string) config('app.url'), (string) $installation->installationId());

        try {
            $activations->changeKey($current, $current ? $this->validation->contextFor($current) : null, $context, (string) $request->input('license_key'), $user, $request->ip());
        } catch (LicenseException $e) {
            return back()->withInput($request->only('email'))->withErrors(['license_key' => $e->getMessage()], 'changeKey');
        }

        $this->validation->forget();
        $activity->log('license.key_changed', __('License key changed on the suspension page'), null, [], $user);

        return $this->validation->snapshot(fresh: true)->isLocked()
            ? redirect()->route('license.locked')->with('error', __('The new key was accepted, but the license is still not usable. Check it in your MusicDesk.tech account.'))
            : redirect()->route('login')->with('status', __('The new license key is active. You can sign in.'));
    }
}

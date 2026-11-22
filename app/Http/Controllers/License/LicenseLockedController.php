<?php

namespace App\Http\Controllers\License;

use App\Exceptions\LicenseException;
use App\Http\Controllers\Controller;
use App\Services\License\LicenseValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Throwable;

/**
 * The suspension notice shown to everyone once an expired license is past
 * its read-only window, and a throttled "check again" after renewing.
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
}

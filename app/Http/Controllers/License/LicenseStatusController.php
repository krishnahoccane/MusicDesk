<?php

namespace App\Http\Controllers\License;

use App\Exceptions\LicenseException;
use App\Http\Controllers\Controller;
use App\Models\LicenseEvent;
use App\Services\Installation\InstallationState;
use App\Services\License\Data\ActivationContext;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseFeatureService;
use App\Services\License\LicenseManager;
use App\Services\License\LicenseValidationService;
use App\Services\License\OfflineRequestFile;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Settings → License Information.
 *
 * There is intentionally no option to bypass or override license validation.
 */
class LicenseStatusController extends Controller
{
    public function __construct(
        protected LicenseValidationService $validation,
        protected LicenseActivationService $activations,
        protected InstallationState $installation,
    ) {}

    public function show(LicenseFeatureService $features, LicenseManager $manager): View
    {
        $snapshot = $this->validation->snapshot();
        $activation = $snapshot->activation ?? $this->validation->activation();
        $currentDomain = ActivationContext::domainFrom((string) config('app.url'));

        return view('license.show', [
            'snapshot' => $snapshot,
            'activation' => $activation,
            'features' => $features->matrix(),
            'currentDomain' => $currentDomain,
            'domainChanged' => $activation && $activation->domain !== $currentDomain,
            'events' => LicenseEvent::query()->with('user:id,name')->latest('id')->limit(10)->get(),
            'driver' => $manager->provider()->name(),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        try {
            $this->validation->refresh($request->user(), $request->ip());
        } catch (LicenseException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('License verified successfully.'));
    }

    public function activate(Request $request): RedirectResponse
    {
        $request->merge(['license_key' => LicenseActivationService::normalizeKey((string) $request->input('license_key'))]);
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:64', 'regex:'.LicenseActivationService::KEY_PATTERN],
        ]);

        $context = ActivationContext::for((string) config('app.url'), (string) $this->installation->installationId());

        try {
            $result = $this->activations->activate($context, $data['license_key']);
            $this->activations->persist($context, $data['license_key'], $result, null, $request->user(), $request->ip());
        } catch (LicenseException $e) {
            throw ValidationException::withMessages(['license_key' => $e->getMessage()]);
        }

        $this->validation->forget();

        return back()->with('success', __('License activated successfully.'));
    }

    /**
     * Change the license key (e.g. a new key after renewal or an upgrade).
     */
    public function changeKey(Request $request): RedirectResponse
    {
        $request->merge(['new_license_key' => LicenseActivationService::normalizeKey((string) $request->input('new_license_key'))]);
        $request->validate([
            'new_license_key' => ['required', 'string', 'max:64', 'regex:'.LicenseActivationService::KEY_PATTERN],
            'change_password' => ['required', 'string'],
        ], [], ['new_license_key' => __('license key'), 'change_password' => __('password')]);

        if (! Hash::check((string) $request->input('change_password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['change_password' => __('The password is incorrect.')]);
        }

        $current = $this->validation->activation();
        $context = ActivationContext::for((string) config('app.url'), (string) $this->installation->installationId());

        try {
            $this->activations->changeKey($current, $current ? $this->validation->contextFor($current) : null, $context, (string) $request->input('new_license_key'), $request->user(), $request->ip());
        } catch (LicenseException $e) {
            throw ValidationException::withMessages(['new_license_key' => $e->getMessage()]);
        }

        $this->validation->forget();

        return back()->with('success', __('The license key was changed. The previous key was released for this domain.'));
    }

    /**
     * Offline activation with a .ldlic file from the MusicDesk portal.
     */
    public function offline(Request $request): RedirectResponse
    {
        $request->validate(['license_file' => ['required', 'file', 'max:64']], [], ['license_file' => __('activation file')]);

        $context = ActivationContext::for((string) config('app.url'), (string) $this->installation->installationId());

        try {
            $result = $this->activations->readOfflineFile($context, (string) file_get_contents($request->file('license_file')->getRealPath()));
            $this->activations->persist($context, '', $result, null, $request->user(), $request->ip(), offline: true);
        } catch (LicenseException $e) {
            throw ValidationException::withMessages(['license_file' => $e->getMessage()]);
        }

        $this->validation->forget();

        return back()->with('success', __('Offline activation file accepted. It is valid until :date.', [
            'date' => Format::date($result->validUntil),
        ]));
    }

    /**
     * Activation request file to upload in the MusicDesk portal.
     */
    public function requestFile(): Response
    {
        return OfflineRequestFile::download(ActivationContext::for((string) config('app.url'), (string) $this->installation->installationId()));
    }

    public function deactivate(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }

        $activation = $this->validation->activation();
        abort_unless($activation, 404);

        try {
            $this->activations->deactivate($activation, $this->validation->contextFor($activation), $request->user(), $request->ip());
        } catch (LicenseException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->validation->forget();

        return back()->with('success', __('This installation has been deactivated and its activation slot released. LabelDesk is now read-only until a license is activated.'));
    }

    public function transfer(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['transfer_password' => __('The password is incorrect.')]);
        }

        $activation = $this->validation->activation();
        abort_unless($activation, 404);

        try {
            $this->activations->transfer($activation, $this->validation->contextFor($activation), (string) config('app.url'), $request->user(), $request->ip());
        } catch (LicenseException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->validation->forget();

        return back()->with('success', __('License transferred to :domain.', ['domain' => ActivationContext::domainFrom((string) config('app.url'))]));
    }
}

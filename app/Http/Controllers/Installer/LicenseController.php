<?php

namespace App\Http\Controllers\Installer;

use App\Exceptions\LicenseException;
use App\Http\Requests\Installer\LicenseRequest;
use App\Models\LicenseActivation;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use App\Services\License\LicenseActivationService;
use App\Services\License\LicenseManager;
use App\Services\License\OfflineRequestFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LicenseController extends InstallerController
{
    public function show(LicenseManager $manager): View|RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        $url = (string) ($this->wizard->get('application')['url'] ?? '');
        $license = $this->wizard->get('license');

        return view('installer.license', [
            'step' => 'license',
            'domain' => ActivationContext::domainFrom($url),
            'activated' => $license['summary'] ?? null,
            'maskedKey' => isset($license['key']) ? LicenseActivation::mask((string) $this->wizard->decrypt($license['key'])) : null,
            'offline' => (bool) ($license['offline'] ?? false),
            'driver' => $manager->provider()->name(),
        ]);
    }

    public function store(LicenseRequest $request, LicenseActivationService $activations, LicenseManager $manager): RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        $url = (string) ($this->wizard->get('application')['url'] ?? '');
        $context = ActivationContext::for($url, $this->state->ensureInstallationId());
        $key = (string) $request->validated('license_key');
        $provider = $manager->provider();

        try {
            $result = $activations->activate($context, $key, $provider->name());
        } catch (LicenseException $e) {
            return back()->withInput($request->only('license_key'))->withErrors(['license_key' => $e->getMessage()]);
        }

        // Keep the signed token (re-verified at install time) and the key
        // and activation secret encrypted.
        $this->remember($key, $provider->name(), $result, false);

        return redirect()->route('installer.license')->with('success', __('License activated.'));
    }

    /**
     * Offline activation: upload the .ldlic file downloaded from the MusicDesk portal.
     */
    public function offline(Request $request, LicenseActivationService $activations, LicenseManager $manager): RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        $request->validate(['license_file' => ['required', 'file', 'max:64']], [], ['license_file' => __('activation file')]);

        $url = (string) ($this->wizard->get('application')['url'] ?? '');
        $context = ActivationContext::for($url, $this->state->ensureInstallationId());
        $provider = $manager->provider();

        try {
            $result = $activations->readOfflineFile($context, (string) file_get_contents($request->file('license_file')->getRealPath()), $provider->name());
        } catch (LicenseException $e) {
            return back()->withErrors(['license_file' => $e->getMessage()]);
        }

        $this->remember('', $provider->name(), $result, true);

        return redirect()->route('installer.license')->with('success', __('Offline activation file accepted.'));
    }

    /**
     * Activation request file for the MusicDesk portal (Licenses → Offline activation).
     */
    public function request(): Response|RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        $url = (string) ($this->wizard->get('application')['url'] ?? '');
        $context = ActivationContext::for($url, $this->state->ensureInstallationId());

        return OfflineRequestFile::download($context);
    }

    protected function remember(string $key, string $provider, LicenseResult $result, bool $offline): void
    {
        // Keep the signed token (re-verified at install time) and the key encrypted.
        $this->wizard->put('license', [
            'key' => $this->wizard->encrypt($key),
            'provider' => $provider,
            'payload' => $result->tokenPayload,
            'signature' => $result->tokenSignature,
            'messages' => $result->messages,
            'offline' => $offline,
            'summary' => $result->summary(),
        ]);
    }

    public function continue(): RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        if (empty($this->wizard->get('license')['payload'])) {
            return redirect()->route('installer.license')->withErrors(['license_key' => __('Activate your license to continue.')]);
        }

        return $this->next('license');
    }
}

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
use Throwable;

/**
 * Step 0: validate the license key with MusicDesk.tech for this site's domain.
 * The signed token is kept in the session and verified again when installing.
 */
class LicenseController extends InstallerController
{
    protected function siteUrl(Request $request): string
    {
        return (string) ($this->wizard->get('site')['url'] ?? $request->getSchemeAndHttpHost().$request->getBasePath());
    }

    public function show(Request $request, LicenseManager $manager): View|RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        $url = $this->siteUrl($request);
        $license = $this->wizard->get('license');

        return view('installer.license', [
            'step' => 'license',
            'url' => $url,
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

        $url = (string) $request->validated('url');
        $this->rememberUrl($url);

        $context = ActivationContext::for($url, $this->state->ensureInstallationId());
        $key = (string) $request->validated('license_key');
        $provider = $manager->provider();

        try {
            $result = $activations->activate($context, $key, $provider->name());
        } catch (LicenseException $e) {
            return back()->withInput($request->only('license_key', 'url'))->withErrors(['license_key' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Before the server check: missing curl / sodium / openssl ends up here.
            report($e);

            return back()->withInput($request->only('license_key', 'url'))->withErrors(['license_key' => __('The license could not be checked on this server (:reason). The next step lists what the server is missing; you can also use offline activation below.', [
                'reason' => class_basename($e),
            ])]);
        }

        $this->remember($key, $provider->name(), $result, false);

        return redirect()->route('installer.license')->with('success', __('License validated and activated for :domain.', ['domain' => $context->domain]));
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

        $context = ActivationContext::for($this->siteUrl($request), $this->state->ensureInstallationId());
        $provider = $manager->provider();

        try {
            $result = $activations->readOfflineFile($context, (string) file_get_contents($request->file('license_file')->getRealPath()), $provider->name());
        } catch (LicenseException $e) {
            return back()->withErrors(['license_file' => $e->getMessage()]);
        }

        $this->rememberUrl($this->siteUrl($request));
        $this->remember('', $provider->name(), $result, true);

        return redirect()->route('installer.license')->with('success', __('Offline activation file accepted.'));
    }

    /**
     * Activation request file for the MusicDesk portal (Licenses → Offline activation).
     */
    public function request(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        return OfflineRequestFile::download(ActivationContext::for($this->siteUrl($request), $this->state->ensureInstallationId()));
    }

    public function continue(): RedirectResponse
    {
        if ($redirect = $this->guard('license')) {
            return $redirect;
        }

        if (empty($this->wizard->get('license')['payload'])) {
            return redirect()->route('installer.license')->withErrors(['license_key' => __('Validate your license key to continue.')]);
        }

        return $this->next('license');
    }

    /**
     * The license is bound to the site's domain; a different address needs a new activation.
     */
    protected function rememberUrl(string $url): void
    {
        $previous = $this->wizard->get('site')['url'] ?? null;
        if ($previous !== null && $previous !== $url) {
            $this->wizard->put('license', []);
            $this->wizard->resetAfter('welcome');
        }
        $this->wizard->put('site', ['url' => $url]);
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
}

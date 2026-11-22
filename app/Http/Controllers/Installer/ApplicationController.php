<?php

namespace App\Http\Controllers\Installer;

use App\Http\Requests\Installer\ApplicationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends InstallerController
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard('application')) {
            return $redirect;
        }

        return view('installer.application', [
            'step' => 'application',
            'values' => $this->wizard->get('application') + [
                'name' => 'LabelDesk',
                'url' => $request->getSchemeAndHttpHost().$request->getBasePath(),
                'timezone' => 'UTC',
                'currency' => 'USD',
                'locale' => 'en',
            ],
            'timezones' => timezone_identifiers_list(),
            'currencies' => (array) config('labeldesk.installer.currencies'),
            'locales' => (array) config('labeldesk.installer.locales'),
        ]);
    }

    public function store(ApplicationRequest $request): RedirectResponse
    {
        if ($redirect = $this->guard('application')) {
            return $redirect;
        }

        $previousUrl = $this->wizard->get('application')['url'] ?? null;
        $this->wizard->put('application', $request->validated());

        // The license is bound to the URL's domain: a new URL needs re-activation.
        if ($previousUrl !== null && $previousUrl !== $request->validated('url')) {
            $this->wizard->put('license', []);
            $this->wizard->resetAfter('administrator');
        }

        return $this->next('application');
    }
}

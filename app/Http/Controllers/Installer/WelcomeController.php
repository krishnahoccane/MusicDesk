<?php

namespace App\Http\Controllers\Installer;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WelcomeController extends InstallerController
{
    public function show(Request $request): View
    {
        $this->state->ensureInstallationId();

        return view('installer.welcome', [
            'step' => 'welcome',
            'resuming' => ($this->state->progress()['status'] ?? null) === 'installing',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['terms' => ['accepted']], [
            'terms.accepted' => __('You must accept the license terms to continue.'),
        ]);

        // New wizard run: fresh session id to prevent session fixation.
        $request->session()->regenerate();

        return $this->next('welcome');
    }
}

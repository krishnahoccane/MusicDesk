<?php

namespace App\Http\Controllers\Installer;

use App\Services\Installation\RequirementsChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RequirementsController extends InstallerController
{
    public function show(RequirementsChecker $checker): View|RedirectResponse
    {
        if ($redirect = $this->guard('requirements')) {
            return $redirect;
        }

        return view('installer.requirements', [
            'step' => 'requirements',
            'groups' => $checker->check(),
            'passes' => $checker->passes(),
        ]);
    }

    public function store(RequirementsChecker $checker): RedirectResponse
    {
        if ($redirect = $this->guard('requirements')) {
            return $redirect;
        }

        if (! $checker->passes()) {
            return back()->with('error', __('Please resolve the failed requirements before continuing.'));
        }

        return $this->next('requirements');
    }
}

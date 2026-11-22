<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Services\Installation\InstallationState;
use App\Services\Installation\InstallerSession;
use Illuminate\Http\RedirectResponse;

/**
 * Shared helpers for installer step controllers.
 */
abstract class InstallerController extends Controller
{
    public function __construct(
        protected InstallerSession $wizard,
        protected InstallationState $state,
    ) {}

    /**
     * Redirect to the first unfinished earlier step, if any.
     */
    protected function guard(string $step): ?RedirectResponse
    {
        $missing = $this->wizard->firstIncompleteBefore($step);

        return $missing ? redirect()->route('installer.'.$missing) : null;
    }

    protected function next(string $step): RedirectResponse
    {
        $this->wizard->complete($step);
        $index = array_search($step, InstallerSession::STEPS, true);
        $next = InstallerSession::STEPS[$index + 1] ?? 'install';

        return redirect()->route('installer.'.$next);
    }
}

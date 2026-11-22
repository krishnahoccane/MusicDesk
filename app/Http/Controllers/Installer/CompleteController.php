<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Services\Installation\InstallationState;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One-time installation summary. Reachable only with the completion token
 * issued by the install step; the page then destroys the progress file, so
 * it cannot be revisited.
 */
class CompleteController extends Controller
{
    public function __invoke(Request $request, InstallationState $state): View
    {
        $progress = $state->progress();
        $token = (string) $request->query('token', '');

        abort_unless(
            $state->isInstalled()
            && ($progress['status'] ?? null) === 'completed'
            && $token !== ''
            && hash_equals((string) ($progress['completion_token'] ?? ''), hash('sha256', $token)),
            404,
        );

        $summary = (array) ($progress['summary'] ?? []);
        $state->clearProgress();

        return view('installer.complete', [
            'step' => 'complete',
            'summary' => $summary,
            'loginUrl' => rtrim((string) ($summary['app_url'] ?? url('/')), '/').'/login',
        ]);
    }
}

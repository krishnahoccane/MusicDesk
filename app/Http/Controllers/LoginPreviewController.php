<?php

namespace App\Http\Controllers;

use App\Support\Appearance;
use Illuminate\View\View;

/**
 * Settings → Appearance: preview a sign-in design with the saved texts (form disabled).
 */
class LoginPreviewController extends Controller
{
    public function __invoke(string $design): View
    {
        abort_unless(array_key_exists($design, Appearance::LOGIN_LAYOUTS), 404);

        return view('auth.login', ['design' => $design, 'preview' => true]);
    }
}

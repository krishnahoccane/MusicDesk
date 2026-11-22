<?php

namespace LabelDesk\Addons\Sso;

use App\Plugins\AddonServiceProvider;
use Illuminate\Auth\Events\Validated;
use Illuminate\Support\Facades\Event;
use LabelDesk\Addons\Sso\Listeners\RequireSingleSignOn;
use LabelDesk\Addons\Sso\Livewire\Settings;
use Livewire\Livewire;

/**
 * Single sign-on with OpenID Connect providers (Microsoft Entra ID, Google
 * Workspace, Okta or any provider with a discovery document).
 */
class SsoServiceProvider extends AddonServiceProvider
{
    public function boot(): void
    {
        $this->views();
        $this->migrations();
        $this->guestRoutes('routes/guest.php');
        $this->webRoutes('routes/web.php');
        $this->settingsTab('sso.settings', 'Single sign-on', 'fingerprint');
        $this->loginButton('sso::login-buttons');

        Livewire::component('sso.settings', Settings::class);

        // "Require single sign-on": refuse password sign-in for staff (Super Admins keep it).
        Event::listen(Validated::class, RequireSingleSignOn::class);
    }
}

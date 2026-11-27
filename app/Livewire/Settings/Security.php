<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\ActivityLog;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → Security: control MusicDesk support access to this site.
 */
class Security extends Component
{
    use EnforcesLicense;

    /** Let MusicDesk support sign in to help (on by default). */
    public bool $support_sso = true;

    /** E-mail the Super Admin whenever support signs in (on by default). */
    public bool $support_sso_email = true;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->support_sso = (bool) $settings->get('security.support_sso', true);
        $this->support_sso_email = (bool) $settings->get('security.support_sso_email', true);
    }

    public function save(SettingsService $settings, ActivityLogger $activity): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();

        $this->validate([
            'support_sso' => ['boolean'],
            'support_sso_email' => ['boolean'],
        ]);

        $settings->setMany([
            'security.support_sso' => $this->support_sso,
            'security.support_sso_email' => $this->support_sso_email,
        ]);
        $activity->log('security.settings', $this->support_sso
            ? __('MusicDesk support sign-in is allowed')
            : __('MusicDesk support sign-in is turned off'));

        $this->dispatch('toast', type: 'success', message: __('Security settings saved.'));
    }

    public function render(): View
    {
        return view('livewire.settings.security', [
            'recentSupport' => ActivityLog::query()->where('event', 'security.support_signin')->latest()->limit(10)->get(),
        ])->title(__('Security settings'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Security')],
        ]]);
    }
}

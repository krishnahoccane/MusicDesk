<?php

namespace LabelDesk\Addons\BulkUpload\Livewire;

use App\Livewire\Concerns\EnforcesLicense;
use App\Services\Activity\ActivityLogger;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → Bulk upload: who besides administrators may bulk upload.
 */
class Settings extends Component
{
    use EnforcesLicense;

    public bool $bulk_staff = true;

    public bool $bulk_artists = false;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);

        $this->bulk_staff = (bool) $settings->get('bulk_upload.staff', true);
        $this->bulk_artists = (bool) $settings->get('bulk_upload.artists', false);
    }

    public function save(SettingsService $settings, ActivityLogger $activity): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
        $this->validate(['bulk_staff' => ['boolean'], 'bulk_artists' => ['boolean']]);

        $settings->setMany(['bulk_upload.staff' => $this->bulk_staff, 'bulk_upload.artists' => $this->bulk_artists]);
        $activity->log('settings.updated', __('Updated bulk upload access'), null, [
            'staff' => $this->bulk_staff, 'artists' => $this->bulk_artists,
        ]);

        $this->dispatch('toast', type: 'success', message: __('Bulk upload access saved.'));
    }

    public function render(): View
    {
        return view('bulk-upload::settings')->title(__('Bulk upload settings'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Bulk upload')],
        ]]);
    }
}

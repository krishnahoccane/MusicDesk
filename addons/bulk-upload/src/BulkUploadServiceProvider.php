<?php

namespace LabelDesk\Addons\BulkUpload;

use App\Enums\SystemRole;
use App\Models\User;
use App\Plugins\AddonServiceProvider;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;
use LabelDesk\Addons\BulkUpload\Livewire\Index;
use LabelDesk\Addons\BulkUpload\Livewire\Settings;
use LabelDesk\Addons\BulkUpload\Services\BulkUploadService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bulk upload: artists and releases with tracks from Excel / CSV templates
 * (Catalog → Bulk upload), with its access switches in Settings → Bulk upload.
 */
class BulkUploadServiceProvider extends AddonServiceProvider
{
    public const PERMISSION = 'catalog.bulk_upload';

    /** Roles that get the permission the first time the add-on is activated. */
    public const DEFAULT_ROLES = [SystemRole::SuperAdmin, SystemRole::LabelManager, SystemRole::ArtistManager];

    public function boot(): void
    {
        $this->views();
        $this->migrations();
        $this->webRoutes('routes/web.php');

        Livewire::component('bulk-upload.index', Index::class);
        Livewire::component('bulk-upload.settings', Settings::class);

        // Administrators always; staff and artist accounts per Settings → Bulk upload.
        Gate::define('bulk-upload', fn (User $user) => $this->app->make(BulkUploadService::class)->typesFor($user) !== []);

        $this->permissions('bulk_upload', __('Bulk upload'), [self::PERMISSION => __('Bulk upload artists and releases')]);
        $this->navigation('Catalog', ['label' => 'Bulk upload', 'icon' => 'upload', 'route' => 'bulk-upload.index',
            'active' => 'bulk-upload.index', 'gate' => 'bulk-upload'], after: 'tracks.index');
        $this->settingsTab('bulk-upload.settings', 'Bulk upload', 'upload');
    }

    /**
     * First activation on a new installation: give the permission to the
     * default roles. Installations that had bulk upload in core already hold
     * it on their roles, and an administrator's later choices are never undone.
     */
    public function activate(): void
    {
        $settings = $this->app->make(SettingsService::class);
        if ($settings->get('bulk_upload.defaults_granted')) {
            return;
        }

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        if (! $permission->roles()->exists()) {
            foreach (self::DEFAULT_ROLES as $role) {
                Role::query()->where('name', $role->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
            $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $settings->set('bulk_upload.defaults_granted', true);
    }
}

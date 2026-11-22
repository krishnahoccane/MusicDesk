<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Essential default: permissions and the six system roles.
 * Idempotent — safe to run on every install and upgrade. Never removes
 * permissions, and never re-grants a permission an administrator removed
 * from a system role.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permission catalogue, grouped by module (see App\Support\PermissionCatalog).
     *
     * @return array<string, list<string>>
     */
    public static function permissions(): array
    {
        return PermissionCatalog::names();
    }

    /**
     * @return array<string, list<string>|string>
     */
    /**
     * Artist accounts: fixed set (the role cannot be edited). Every query is
     * additionally limited to the account's own artists (App\Support\ArtistAccess).
     */
    public const ARTIST_PERMISSIONS = [
        'artists.view', 'artists.update',
        'releases.view', 'releases.create', 'releases.update', 'releases.submit',
        'tracks.view', 'tracks.create', 'tracks.update', 'tracks.delete', 'tracks.download_master',
        'contributors.view', 'contributors.manage',
        'contracts.view', 'documents.view',
        'distribution.view', 'payments.view',
    ];

    public static function roles(): array
    {
        $all = collect(self::permissions())->flatten()->all();

        return [
            SystemRole::SuperAdmin->value => '*',
            SystemRole::LabelManager->value => array_values(array_diff($all, [
                'users.manage', 'roles.manage', 'settings.manage', 'backups.manage', 'license.manage', 'plugins.manage',
            ])),
            SystemRole::ArtistManager->value => [
                'artists.view', 'artists.create', 'artists.update',
                'releases.view', 'releases.create', 'releases.update', 'releases.submit',
                'tracks.view', 'tracks.create', 'tracks.update',
                'contributors.view', 'contributors.manage',
                'contracts.view', 'documents.view', 'documents.manage',
                'distribution.view', 'reports.view',
            ],
            SystemRole::DistributionManager->value => [
                'artists.view', 'releases.view', 'releases.review', 'tracks.view', 'tracks.download_master',
                'contributors.view', 'distribution.view', 'distribution.manage', 'dsps.manage',
                'reports.view', 'reports.export',
            ],
            SystemRole::Accountant->value => [
                'artists.view', 'releases.view', 'tracks.view', 'contracts.view',
                'royalties.view', 'royalties.import', 'royalties.manage', 'payments.view', 'payments.manage',
                'reports.view', 'reports.export', 'reports.financial',
            ],
            SystemRole::Viewer->value => [
                'artists.view', 'releases.view', 'tracks.view', 'contributors.view',
                'distribution.view', 'reports.view',
            ],
            SystemRole::Artist->value => self::ARTIST_PERMISSIONS,
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Permissions introduced by this version (absent before this run).
        $existing = Permission::query()->pluck('name')->all();
        $new = [];
        foreach (collect(self::permissions())->flatten() as $name) {
            Permission::findOrCreate($name, 'web');
            if (! in_array($name, $existing, true)) {
                $new[] = $name;
            }
        }

        foreach (self::roles() as $roleName => $permissions) {
            $isNewRole = ! Role::query()->where('name', $roleName)->where('guard_name', 'web')->exists();
            $role = Role::findOrCreate($roleName, 'web');

            // Super Admin passes every gate via Gate::before, but also holds all
            // permissions explicitly so permission listings are accurate.
            if ($permissions === '*') {
                $wanted = Permission::query()->pluck('name')->all();
            } else {
                // Administrators may edit system roles. On upgrades only grant
                // permissions that are new in this version, so their choices
                // are never undone.
                $wanted = $isNewRole ? $permissions : array_values(array_intersect($permissions, $new));
            }

            if ($roleName === SystemRole::Artist->value) {
                $role->syncPermissions($permissions); // fixed set, never edited

                continue;
            }

            $missing = array_diff($wanted, $role->permissions()->pluck('name')->all());
            if ($missing) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

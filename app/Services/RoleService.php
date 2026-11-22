<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Exceptions\UserManagementException;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\License\LicenseService;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles & permissions administration.
 *
 *  - Super Admin always holds every permission and cannot be edited.
 *  - Other system roles keep their names and cannot be deleted, but their
 *    permissions can be changed.
 *  - Creating, renaming and deleting custom roles requires the license
 *    feature "custom_roles".
 *  - Nobody can grant a permission they do not hold themselves.
 */
class RoleService
{
    public function __construct(
        protected LicenseService $license,
        protected ActivityLogger $activity,
    ) {}

    public static function isSystem(Role $role): bool
    {
        return SystemRole::tryFrom($role->name) !== null;
    }

    public static function isSuperAdmin(Role $role): bool
    {
        return $role->name === SystemRole::SuperAdmin->value;
    }

    /** The Artist role has a fixed permission set. */
    public static function isArtist(Role $role): bool
    {
        return $role->name === SystemRole::Artist->value;
    }

    public function customRolesEnabled(): bool
    {
        return $this->license->canUse('custom_roles');
    }

    /**
     * @param  list<string>  $permissions
     */
    public function create(User $actor, string $name, array $permissions): Role
    {
        $this->ensureCustomRolesEnabled();
        $permissions = $this->validatePermissions($actor, $permissions, []);

        $role = DB::transaction(function () use ($name, $permissions) {
            $role = Role::create(['name' => trim($name), 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            return $role;
        });

        $this->activity->log('role.created', __('Created role :name', ['name' => $role->name]), $role, [
            'permissions' => $permissions,
        ]);

        return $role;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function update(User $actor, Role $role, string $name, array $permissions): Role
    {
        if (self::isSuperAdmin($role)) {
            throw new UserManagementException(__('The Super Admin role always has every permission and cannot be changed.'));
        }

        $name = trim($name);
        $renamed = $name !== $role->name;
        if ($renamed && self::isSystem($role)) {
            throw new UserManagementException(__('Built-in roles cannot be renamed.'));
        }
        if ($renamed) {
            $this->ensureCustomRolesEnabled();
        }

        $before = $role->permissions()->pluck('name')->all();
        $permissions = $this->validatePermissions($actor, $permissions, $before);

        if ($actor->hasRole($role->name) && ! $actor->hasRole(SystemRole::SuperAdmin->value)
            && array_diff($before, $permissions) !== []) {
            throw new UserManagementException(__('You cannot remove permissions from a role you hold yourself.'));
        }

        DB::transaction(function () use ($role, $name, $permissions) {
            $role->name = $name;
            $role->save();
            $role->syncPermissions($permissions);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('role.updated', __('Updated role :name', ['name' => $role->name]), $role, array_filter([
            'granted' => array_values(array_diff($permissions, $before)) ?: null,
            'revoked' => array_values(array_diff($before, $permissions)) ?: null,
            'renamed' => $renamed ? $name : null,
        ]));

        return $role;
    }

    public function delete(User $actor, Role $role): void
    {
        if (self::isSystem($role)) {
            throw new UserManagementException(__('Built-in roles cannot be deleted.'));
        }
        $this->ensureCustomRolesEnabled();

        $assigned = User::withTrashed()->role($role->name)->count();
        if ($assigned > 0) {
            throw new UserManagementException(trans_choice(
                '{1} This role is assigned to 1 user. Give that user another role first.|[2,*] This role is assigned to :count users. Give them another role first.',
                $assigned,
                ['count' => $assigned],
            ));
        }

        $name = $role->name;
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('role.deleted', __('Deleted role :name', ['name' => $name]), null, ['role' => $name]);
    }

    protected function ensureCustomRolesEnabled(): void
    {
        if (! $this->customRolesEnabled()) {
            throw new UserManagementException(__('Custom roles are not included in your license plan.'));
        }
    }

    /**
     * Keeps only known permissions and refuses to grant anything the actor
     * does not hold (permissions the role already had may stay).
     *
     * @param  list<string>  $requested
     * @param  list<string>  $existing
     * @return list<string>
     */
    protected function validatePermissions(User $actor, array $requested, array $existing): array
    {
        $requested = array_values(array_unique(array_intersect($requested, PermissionCatalog::all())));

        if ($actor->hasRole(SystemRole::SuperAdmin->value)) {
            return $requested;
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $escalation = array_diff($requested, $held, $existing);

        if ($escalation !== []) {
            throw new UserManagementException(__('You cannot grant permissions you do not have yourself.'));
        }

        return $requested;
    }
}

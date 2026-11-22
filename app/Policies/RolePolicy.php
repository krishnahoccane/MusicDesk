<?php

namespace App\Policies;

use App\Models\User;
use App\Services\RoleService;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->can('roles.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('roles.manage');
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->can('roles.manage') && ! RoleService::isSuperAdmin($role) && ! RoleService::isArtist($role);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->can('roles.manage') && ! RoleService::isSystem($role);
    }
}

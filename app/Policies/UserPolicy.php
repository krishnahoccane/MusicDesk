<?php

namespace App\Policies;

use App\Models\User;
use App\Services\UserService;

/**
 * Who may manage which account. Policies run for Super Admins too (Gate::before
 * only short-circuits permission names). The business invariants (last Super
 * Admin, license allowance) are additionally enforced in UserService.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can('users.manage') && app(UserService::class)->canManage($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->isNot($user) && $this->update($actor, $user);
    }

    public function restore(User $actor, User $user): bool
    {
        return $this->update($actor, $user);
    }
}

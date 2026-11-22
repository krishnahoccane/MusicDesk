<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Exceptions\UserManagementException;
use App\Models\Artist;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\License\LicenseFeatureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * User administration. Enforces the rules that must hold no matter which
 * screen (or which Super Admin, who passes every gate) makes the change:
 *
 *  - active users never exceed the license user allowance;
 *  - there is always at least one active Super Admin;
 *  - nobody deactivates, deletes or re-roles their own account;
 *  - nobody grants a role carrying permissions they do not hold themselves.
 */
class UserService
{
    public function __construct(
        protected LicenseFeatureService $features,
        protected ActivityLogger $activity,
    ) {}

    // ---------------------------------------------------------------- seats

    public function seatLimit(): ?int
    {
        return $this->features->userLimit();
    }

    public function activeCount(): int
    {
        return User::query()->where('is_active', true)->count();
    }

    public function seatsAvailable(): bool
    {
        $limit = $this->seatLimit();

        return $limit === null || $this->activeCount() < $limit;
    }

    protected function ensureSeatAvailable(): void
    {
        if (! $this->seatsAvailable()) {
            throw UserManagementException::seatLimit((int) $this->seatLimit());
        }
    }

    // ---------------------------------------------------------------- roles

    /**
     * Roles the actor may assign: everything for a Super Admin; otherwise
     * only roles whose permissions are a subset of the actor's own.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor): Collection
    {
        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get();

        if ($actor->hasRole(SystemRole::SuperAdmin->value)) {
            return $roles;
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();

        return $roles->filter(fn (Role $role) => $role->name !== SystemRole::SuperAdmin->value
            && array_diff($role->permissions->pluck('name')->all(), $held) === [])->values();
    }

    /**
     * Whether the actor may manage the target account at all.
     */
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->hasRole(SystemRole::SuperAdmin->value)) {
            return true;
        }

        if ($target->hasRole(SystemRole::SuperAdmin->value)) {
            return false;
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();

        return array_diff($target->getAllPermissions()->pluck('name')->all(), $held) === [];
    }

    protected function ensureAssignable(User $actor, string $roleName): Role
    {
        $role = $this->assignableRoles($actor)->firstWhere('name', $roleName);

        if (! $role) {
            throw new UserManagementException(__('You cannot assign the :role role.', ['role' => $roleName]));
        }

        return $role;
    }

    protected function ensureCanManage(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            throw new UserManagementException(__('You cannot change this account because it has more access than you.'));
        }
    }

    protected function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(SystemRole::SuperAdmin->value);
    }

    protected function ensureAnotherSuperAdmin(User $user): void
    {
        if (! $this->isSuperAdmin($user)) {
            return;
        }

        $others = User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->role(SystemRole::SuperAdmin->value)
            ->exists();

        if (! $others) {
            throw new UserManagementException(__('LabelDesk needs at least one active Super Admin. Make another user a Super Admin first.'));
        }
    }

    // ---------------------------------------------------------------- actions

    /**
     * @param  array{name: string, email: string, password?: ?string, is_active?: bool}  $data
     */
    public function create(User $actor, array $data, string $roleName, bool $sendPasswordLink = false): User
    {
        $role = $this->ensureAssignable($actor, $roleName);
        $active = $data['is_active'] ?? true;

        $user = DB::transaction(function () use ($data, $role, $active) {
            if ($active) {
                $this->ensureSeatAvailable();
            }

            $user = new User;
            $user->fill([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                // Invited users get an unusable random password until they set their own.
                'password' => filled($data['password'] ?? null) ? $data['password'] : Str::password(40),
            ]);
            $user->forceFill(['is_active' => $active, 'email_verified_at' => now()]);
            $user->save();
            $user->syncRoles([$role->name]);
            $this->syncArtistLinks($user, $role->name, $data['artist_ids'] ?? []);

            return $user;
        });

        $this->activity->log('user.created', __('Created user :name', ['name' => $user->name]), $user, [
            'email' => $user->email, 'role' => $role->name, 'active' => $active,
        ]);

        if ($sendPasswordLink) {
            $this->sendPasswordLink($actor, $user);
        }

        return $user;
    }

    /**
     * @param  array{name: string, email: string, password?: ?string}  $data
     */
    public function update(User $actor, User $user, array $data, string $roleName): User
    {
        $this->ensureCanManage($actor, $user);
        $currentRole = $user->getRoleNames()->first();
        $roleChanged = $currentRole !== $roleName;

        if ($roleChanged) {
            if ($actor->is($user)) {
                throw new UserManagementException(__('You cannot change your own role.'));
            }
            $this->ensureAssignable($actor, $roleName);
            if ($roleName !== SystemRole::SuperAdmin->value) {
                $this->ensureAnotherSuperAdmin($user);
            }
        }

        $passwordChanged = filled($data['password'] ?? null);
        $original = ['name' => $user->name, 'email' => $user->email];

        DB::transaction(function () use ($user, $data, $roleChanged, $roleName, $passwordChanged) {
            $user->fill([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
            ]);
            if ($passwordChanged) {
                $user->password = $data['password'];
                $user->setRememberToken(Str::random(60));
            }
            $user->save();

            if ($roleChanged) {
                $user->syncRoles([$roleName]);
            }
            if (array_key_exists('artist_ids', $data)) {
                $this->syncArtistLinks($user, $roleName, $data['artist_ids']);
            }
        });

        if ($passwordChanged) {
            $actor->is($user) ? $this->keepCurrentSession($user) : $this->terminateSessions($user);
        }

        $changes = array_filter([
            'name' => $original['name'] !== $user->name ? [$original['name'], $user->name] : null,
            'email' => $original['email'] !== $user->email ? [$original['email'], $user->email] : null,
            'role' => $roleChanged ? [$currentRole, $roleName] : null,
            'credentials' => $passwordChanged ? 'changed' : null,
        ]);

        $this->activity->log('user.updated', __('Updated user :name', ['name' => $user->name]), $user, $changes);

        return $user;
    }

    /**
     * Artist accounts must be linked to at least one artist profile; other
     * accounts never keep artist links.
     *
     * @param  array<int|string>  $artistIds
     */
    protected function syncArtistLinks(User $user, string $roleName, array $artistIds): void
    {
        if ($roleName !== SystemRole::Artist->value) {
            $user->artists()->detach();
            $user->forgetLinkedArtists();

            return;
        }

        $ids = Artist::query()->whereIn('id', array_map('intval', $artistIds))->pluck('id')->all();
        if ($ids === []) {
            throw new UserManagementException(__('Choose the artist profiles this artist account may manage.'));
        }

        $user->artists()->sync($ids);
        $user->forgetLinkedArtists();
    }

    public function setActive(User $actor, User $user, bool $active): void
    {
        if ($user->is_active === $active) {
            return;
        }

        $this->ensureCanManage($actor, $user);

        if (! $active) {
            if ($actor->is($user)) {
                throw new UserManagementException(__('You cannot deactivate your own account.'));
            }
            $this->ensureAnotherSuperAdmin($user);
        }

        DB::transaction(function () use ($user, $active) {
            if ($active) {
                $this->ensureSeatAvailable();
            }
            $user->forceFill(['is_active' => $active])->save();
        });

        if (! $active) {
            $this->terminateSessions($user);
        }

        $this->activity->log(
            $active ? 'user.activated' : 'user.deactivated',
            ($active ? __('Activated user :name', ['name' => $user->name]) : __('Deactivated user :name', ['name' => $user->name])),
            $user,
        );
    }

    public function delete(User $actor, User $user): void
    {
        $this->ensureCanManage($actor, $user);

        if ($actor->is($user)) {
            throw new UserManagementException(__('You cannot delete your own account.'));
        }
        $this->ensureAnotherSuperAdmin($user);

        // Soft delete keeps the audit trail and authorship of records intact.
        $user->forceFill(['is_active' => false])->save();
        $user->delete();
        $this->terminateSessions($user);

        $this->activity->log('user.deleted', __('Deleted user :name', ['name' => $user->name]), $user, ['email' => $user->email]);
    }

    public function restore(User $actor, User $user): void
    {
        $this->ensureCanManage($actor, $user);

        // Restored accounts come back deactivated; activating them is a
        // separate step that checks the user allowance.
        $user->restore();

        $this->activity->log('user.restored', __('Restored user :name', ['name' => $user->name]), $user);
    }

    public function sendPasswordLink(User $actor, User $user): bool
    {
        $this->ensureCanManage($actor, $user);

        if (! $user->is_active) {
            throw new UserManagementException(__('Activate the account before sending a password link.'));
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            throw new UserManagementException(__('A password link was sent to this user moments ago. Please wait before sending another.'));
        }

        $sent = $status === Password::RESET_LINK_SENT;
        if ($sent) {
            $this->activity->log('user.reset_link_sent', __('Sent a password link to :name', ['name' => $user->name]), $user);
        }

        return $sent;
    }

    /**
     * After users change their own password, keep the current session valid
     * (AuthenticateSession compares the stored password hash).
     */
    public function keepCurrentSession(User $user): void
    {
        if (app()->bound('session') && auth()->guard('web')->id() === $user->getKey()) {
            session()->put('password_hash_web', auth()->guard('web')->hashPasswordForCookie($user->getAuthPassword()));
        }
    }

    /**
     * End every session of a user: database sessions are deleted and the
     * remember-me token rotated. (With file sessions, AuthenticateSession and
     * EnsureUserIsActive sign the user out on their next request.)
     */
    public function terminateSessions(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}

<?php

namespace LabelDesk\Addons\Sso;

use App\Enums\SystemRole;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LabelDesk\Addons\Sso\Models\SsoIdentity;
use LabelDesk\Addons\Sso\Models\SsoProvider;
use LabelDesk\Addons\Sso\Oidc\OidcClient;
use LabelDesk\Addons\Sso\Oidc\SsoException;
use Spatie\Permission\Models\Role;

/**
 * Maps verified ID-token claims to a LabelDesk account:
 *
 *  1. a user already linked to this provider subject ("sub");
 *  2. otherwise an existing user with the same, provider-verified email address
 *     (the link is remembered);
 *  3. otherwise, only when the administrator enabled it for this provider and
 *     the email domain is allowed, a new user with the provider's default role
 *     (never Super Admin, never an artist account; within the license seats).
 *
 * Deactivated and deleted accounts are refused with the same message as an
 * unknown account.
 */
class SsoAuthenticator
{
    public function __construct(
        protected UserService $users,
        protected ActivityLogger $activity,
    ) {}

    /** @param  array<string, mixed>  $claims */
    public function resolve(SsoProvider $provider, array $claims): User
    {
        $subject = (string) $claims['sub'];
        $email = OidcClient::verifiedEmail($claims);

        $identity = SsoIdentity::query()->where('sso_provider_id', $provider->id)->where('subject', $subject)->first();
        if ($identity !== null) {
            $user = User::query()->find($identity->user_id);
            $this->ensureAllowed($provider, $email ?? $identity->email ?? $user?->email);

            return $this->usable($user);
        }

        if ($email === null) {
            throw new SsoException(__('Your identity provider did not confirm your email address, so LabelDesk cannot match it to an account.'), 'email_unverified');
        }
        $this->ensureAllowed($provider, $email);

        $user = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user === null) {
            $user = $this->createUser($provider, $claims, $email);
        }
        $user = $this->usable($user);

        // One identity per user and provider: a different "sub" for an already linked user is refused.
        if (SsoIdentity::query()->where('sso_provider_id', $provider->id)->where('user_id', $user->id)->exists()) {
            throw new SsoException(__('This account is already linked to a different :provider identity. Ask your administrator.', ['provider' => $provider->name]), 'identity_conflict');
        }

        SsoIdentity::query()->create([
            'sso_provider_id' => $provider->id,
            'user_id' => $user->id,
            'subject' => $subject,
            'email' => $email,
        ]);
        $this->activity->log('sso.linked', __('Linked :provider sign-in to :name', ['provider' => $provider->name, 'name' => $user->name]), $user, [
            'provider' => $provider->name,
        ], causer: $user);

        return $user;
    }

    protected function ensureAllowed(SsoProvider $provider, ?string $email): void
    {
        if ($email === null || ! $provider->allowsEmail($email)) {
            throw new SsoException(__('Your email domain is not allowed to sign in with :provider.', ['provider' => $provider->name]), 'domain_not_allowed');
        }
    }

    protected function usable(?User $user): User
    {
        if ($user === null || $user->trashed() || ! $user->is_active) {
            throw new SsoException(__('There is no active :app account for you. Ask your administrator for access.', ['app' => config('app.name')]), 'no_active_account');
        }

        return $user;
    }

    /** @param  array<string, mixed>  $claims */
    protected function createUser(SsoProvider $provider, array $claims, string $email): User
    {
        $role = $provider->jit_enabled ? static::assignableRole($provider->jit_role) : null;
        if ($role === null || $provider->domains() === []) {
            throw new SsoException(__('There is no active :app account for you. Ask your administrator for access.', ['app' => config('app.name')]), 'no_active_account');
        }

        $user = DB::transaction(function () use ($claims, $email, $role) {
            if (! $this->users->seatsAvailable()) {
                throw new SsoException(__('All user seats of the :app license are in use. Ask your administrator for access.', ['app' => config('app.name')]), 'seat_limit');
            }
            $name = trim((string) ($claims['name'] ?? trim(($claims['given_name'] ?? '').' '.($claims['family_name'] ?? ''))));

            $user = new User;
            $user->fill([
                'name' => Str::limit($name !== '' ? $name : Str::before($email, '@'), 120, ''),
                'email' => $email,
                'password' => Str::password(40), // unusable until the user resets it
            ]);
            $user->forceFill(['is_active' => true, 'email_verified_at' => now()]);
            $user->save();
            $user->syncRoles([$role->name]);

            return $user;
        });

        $this->activity->log('user.created', __('Created user :name', ['name' => $user->name]), $user, [
            'email' => $user->email, 'role' => $role->name, 'active' => true, 'source' => 'sso:'.$provider->name,
        ], causer: $user);

        return $user;
    }

    /** Roles new SSO users may receive: anything but Super Admin and artist accounts. */
    public static function assignableRole(?string $name): ?Role
    {
        if ($name === null || in_array($name, [SystemRole::SuperAdmin->value, SystemRole::Artist->value], true)) {
            return null;
        }

        return Role::query()->where('name', $name)->first();
    }
}

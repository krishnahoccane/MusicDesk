<?php

namespace LabelDesk\Addons\Sso;

use App\Enums\SystemRole;
use App\Models\User;
use App\Services\SettingsService;
use LabelDesk\Addons\Sso\Models\SsoProvider;

/**
 * "Require single sign-on": staff must sign in with an identity provider;
 * Super Admins always keep password sign-in (break-glass). Artist accounts
 * are only included when the administrator chooses so.
 */
class SsoPolicy
{
    public const REQUIRE = 'sso.require_sso';

    public const INCLUDE_ARTISTS = 'sso.require_sso_artists';

    public function __construct(protected SettingsService $settings) {}

    public function required(): bool
    {
        return (bool) $this->settings->get(self::REQUIRE, false);
    }

    public function includesArtists(): bool
    {
        return (bool) $this->settings->get(self::INCLUDE_ARTISTS, false);
    }

    /** Whether password sign-in is refused for this user right now. */
    public function passwordBlockedFor(User $user): bool
    {
        if (! $this->required() || ! SsoProvider::query()->enabled()->exists()) {
            return false; // never lock everyone out when no provider can be used
        }
        if ($user->hasRole(SystemRole::SuperAdmin->value)) {
            return false;
        }

        return ! $user->hasRole(SystemRole::Artist->value) || $this->includesArtists();
    }
}

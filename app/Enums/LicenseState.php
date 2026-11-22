<?php

namespace App\Enums;

/**
 * Local license state, derived from the last verified signed token.
 *
 * Active      verified token is current — full access to licensed features.
 * GracePeriod token expired but within the offline grace period — full access,
 *             with a warning to restore connectivity.
 * Restricted  license invalid/expired/suspended or grace period exhausted —
 *             read-only mode. Data is never deleted or modified.
 * Unlicensed  no activation recorded (e.g. after deactivation) — read-only.
 * Expired     license expired — read-only for 10 days after the expiry date.
 * Locked      more than 10 days after expiry — nobody can sign in until renewed.
 */
enum LicenseState: string
{
    case Active = 'active';
    case GracePeriod = 'grace';
    case Restricted = 'restricted';
    case Unlicensed = 'unlicensed';
    case Expired = 'expired';
    case Locked = 'locked';

    public function allowsWrites(): bool
    {
        return $this === self::Active || $this === self::GracePeriod;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::GracePeriod => __('Offline grace period'),
            self::Restricted => __('Restricted (read-only)'),
            self::Unlicensed => __('Not activated'),
            self::Expired => __('Expired (read-only)'),
            self::Locked => __('Suspended (license expired)'),
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::GracePeriod => 'warning',
            default => 'danger',
        };
    }
}

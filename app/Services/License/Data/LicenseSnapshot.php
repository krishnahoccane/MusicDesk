<?php

namespace App\Services\License\Data;

use App\Enums\LicenseState;
use App\Models\LicenseActivation;
use Carbon\CarbonImmutable;

/**
 * The installation's license status at a point in time.
 */
final class LicenseSnapshot
{
    public function __construct(
        public readonly LicenseState $state,
        public readonly ?string $reason = null,
        public readonly ?LicenseResult $result = null,
        public readonly ?LicenseActivation $activation = null,
        public readonly ?CarbonImmutable $graceEndsAt = null,
        public readonly ?CarbonImmutable $expiredAt = null,
        public readonly ?CarbonImmutable $lockAt = null,
    ) {}

    public function isLocked(): bool
    {
        return $this->state === LicenseState::Locked;
    }

    /**
     * Expiry date when the license expires within the notice period (30 days), else null.
     */
    public function expiringOn(): ?CarbonImmutable
    {
        $expires = $this->result?->expiresAt;
        if ($this->expiredAt !== null || $expires === null) {
            return null;
        }

        return $expires->isFuture() && $expires->lte(now()->addDays((int) config('licensing.expiring_notice_days', 30))) ? $expires : null;
    }

    /** Status "expiring" from the server, with or without an exact date. */
    public function isExpiring(): bool
    {
        return $this->expiredAt === null && ($this->expiringOn() !== null || $this->result?->status === 'expiring');
    }

    /** Last day people can still sign in (read-only), i.e. the day before the lock. */
    public function lastAccessDay(): ?CarbonImmutable
    {
        return $this->lockAt?->subDay();
    }

    public function allowsWrites(): bool
    {
        return $this->state->allowsWrites();
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        // Read-only after expiry still shows every module of the plan.
        if ($this->result === null && $this->state === LicenseState::Expired) {
            return array_values((array) ($this->activation?->features ?? []));
        }

        return $this->result?->features ?? [];
    }
}

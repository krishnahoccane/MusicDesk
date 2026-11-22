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
    ) {}

    public function allowsWrites(): bool
    {
        return $this->state->allowsWrites();
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return $this->result?->features ?? [];
    }
}

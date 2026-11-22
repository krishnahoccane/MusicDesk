<?php

namespace App\Services\License;

use App\Models\LicenseActivation;
use App\Services\License\Data\LicenseSnapshot;

/**
 * Entry point used by the rest of the application. Business modules depend on
 * this class only and never on providers or the API client.
 */
class LicenseService
{
    public function __construct(
        protected LicenseValidationService $validation,
        protected LicenseFeatureService $features,
    ) {}

    public function snapshot(): LicenseSnapshot
    {
        return $this->validation->snapshot();
    }

    public function activation(): ?LicenseActivation
    {
        return $this->snapshot()->activation ?? $this->validation->activation();
    }

    public function allowsWrites(): bool
    {
        return $this->snapshot()->allowsWrites();
    }

    public function canUse(string $feature): bool
    {
        return $this->features->canUse($feature);
    }
}

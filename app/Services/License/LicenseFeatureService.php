<?php

namespace App\Services\License;

/**
 * Central feature entitlement check: canUse('royalties').
 *
 * Features come from the verified license token issued by MusicDesk.tech —
 * nothing is hard-coded per plan in LabelDesk.
 */
class LicenseFeatureService
{
    public function __construct(protected LicenseValidationService $validation) {}

    public function canUse(string $feature): bool
    {
        return in_array($feature, $this->validation->snapshot()->features(), true);
    }

    /**
     * @return array<string, array{label: string, enabled: bool}>
     */
    public function matrix(): array
    {
        $enabled = $this->validation->snapshot()->features();

        return collect((array) config('licensing.features'))
            ->mapWithKeys(fn (string $label, string $key) => [$key => [
                'label' => __($label),
                'enabled' => in_array($key, $enabled, true),
            ]])
            ->all();
    }

    public function userLimit(): ?int
    {
        return $this->validation->snapshot()->result?->userLimit;
    }
}

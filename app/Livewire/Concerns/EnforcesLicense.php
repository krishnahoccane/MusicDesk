<?php

namespace App\Livewire\Concerns;

use App\Services\License\LicenseService;

/**
 * For Livewire components: call $this->ensureLicenseAllowsWrites() at the
 * start of every action that changes data, and ensureFeature('x') for
 * plan-gated features.
 */
trait EnforcesLicense
{
    protected function ensureLicenseAllowsWrites(): void
    {
        abort_unless(app(LicenseService::class)->allowsWrites(), 403, __('Changes are disabled until the license is verified.'));
    }

    protected function ensureFeature(string $feature): void
    {
        abort_unless(app(LicenseService::class)->canUse($feature), 403, __('This feature is not included in your license plan.'));
    }
}

<?php

namespace Tests\Licensing\Support;

use App\Exceptions\LicenseException;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use App\Services\License\Providers\MockLicenseProvider;

/**
 * The mock authority, but unreachable — simulates a server that has lost
 * its internet connection after activation.
 */
class OfflineMockLicenseProvider extends MockLicenseProvider
{
    public function checkIn(ActivationContext $context, string $token): LicenseResult
    {
        throw LicenseException::unreachable();
    }
}

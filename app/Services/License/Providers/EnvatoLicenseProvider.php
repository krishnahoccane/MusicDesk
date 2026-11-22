<?php

namespace App\Services\License\Providers;

use App\Exceptions\LicenseException;
use App\Services\License\Contracts\LicenseProviderInterface;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;

/**
 * Placeholder for future Envato Market distribution.
 *
 * Planned flow: the customer enters an Envato purchase code, LabelDesk sends
 * it to MusicDesk.tech, which validates it with the Envato API server-side and
 * returns a normal signed LabelDesk token.
 * LabelDesk itself will never call Envato APIs or store an Envato token.
 *
 * Not available in LabelDesk 1.0: every call fails closed.
 */
class EnvatoLicenseProvider implements LicenseProviderInterface
{
    public function name(): string
    {
        return 'envato';
    }

    public function salesChannel(): string
    {
        return 'envato';
    }

    public function publicKey(): ?string
    {
        return null;
    }

    public function activate(ActivationContext $context, string $licenseKey): LicenseResult
    {
        throw $this->unavailable();
    }

    public function checkIn(ActivationContext $context, string $token): LicenseResult
    {
        throw $this->unavailable();
    }

    public function deactivate(ActivationContext $context, string $token): void
    {
        throw $this->unavailable();
    }

    protected function unavailable(): LicenseException
    {
        return new LicenseException('provider_unavailable', __('This license source is not available in this version of LabelDesk.'));
    }
}

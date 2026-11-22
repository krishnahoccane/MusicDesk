<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use App\Models\LicenseActivation;
use App\Models\LicenseCache;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;

/**
 * Stores and re-verifies signed license tokens for offline operation.
 */
class LicenseCacheService
{
    public function __construct(
        protected LicenseSignatureVerifier $verifier,
        protected LicenseManager $manager,
    ) {}

    public function store(LicenseActivation $activation, LicenseResult $result): LicenseCache
    {
        // Keep only a short history.
        $activation->caches()->where('issued_at', '<', now()->subDays(30))->delete();

        return $activation->caches()->create([
            'payload' => $result->tokenPayload,
            'signature' => $result->tokenSignature,
            'issued_at' => $result->issuedAt,
            'valid_until' => $result->validUntil,
        ]);
    }

    /**
     * Re-verify the most recent cached token. Expired tokens are returned
     * (grace-period logic decides what they allow); tampered ones are not.
     *
     * @throws LicenseException
     */
    public function latest(LicenseActivation $activation, ActivationContext $context): ?LicenseResult
    {
        $cache = $activation->caches()->orderByDesc('issued_at')->orderByDesc('id')->first();

        if (! $cache) {
            return null;
        }

        return $this->verifier->verify(
            $cache->payload,
            $cache->signature,
            $this->manager->provider($activation->provider)->publicKey(),
            $context,
            allowExpiredToken: true,
        );
    }
}

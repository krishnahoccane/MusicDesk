<?php

namespace App\Services\License\Data;

use Carbon\CarbonImmutable;

/**
 * A verified license token from the licensing authority.
 *
 * Only LicenseSignatureVerifier creates instances from remote data, after the
 * Ed25519 signature has been checked, so a LicenseResult is always trusted.
 *
 * tokenPayload holds the JWT signing input ("header.payload") and
 * tokenSignature the base64url signature; together they are the token.
 */
final class LicenseResult
{
    /** License statuses that allow LabelDesk to run (annual licenses keep running after expiry). */
    public const USABLE_STATUSES = ['active', 'expiring', 'expired', 'pending'];

    /**
     * @param  list<string>  $features
     * @param  array<string, mixed>  $payload  Decoded token claims
     * @param  list<string>  $messages  Notices from the license server for the administrator
     */
    public function __construct(
        public readonly string $status,
        public readonly string $plan,
        public readonly ?string $licenseType,
        public readonly string $salesChannel,
        public readonly array $features,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?int $activationLimit,
        public readonly ?int $activationsUsed,
        public readonly ?int $userLimit,
        public readonly ?CarbonImmutable $supportUntil,
        public readonly ?CarbonImmutable $updatesUntil,
        public readonly ?string $activationId,
        public readonly ?string $activationSecret,
        public readonly string $tokenPayload,
        public readonly string $tokenSignature,
        public readonly CarbonImmutable $issuedAt,
        public readonly CarbonImmutable $validUntil,
        public readonly array $payload,
        public readonly ?string $message = null,
        public readonly ?string $maxVersion = null,
        public readonly array $messages = [],
        public readonly bool $checkInRequested = false,
        public readonly ?string $environment = null,
    ) {}

    /** The full compact JWT. */
    public function token(): string
    {
        return $this->tokenPayload.'.'.$this->tokenSignature;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::USABLE_STATUSES, true)
            && ($this->expiresAt === null || $this->expiresAt->isFuture() || $this->status === 'expired');
    }

    /**
     * Data safe to keep in the installer session (the token is public signed data).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'status' => $this->status,
            'plan' => $this->plan,
            'license_type' => $this->licenseType,
            'features' => $this->features,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'support_until' => $this->supportUntil?->toIso8601String(),
            'activation_limit' => $this->activationLimit,
            'activations_used' => $this->activationsUsed,
            'user_limit' => $this->userLimit,
            'message' => $this->message,
            'messages' => $this->messages,
        ];
    }
}

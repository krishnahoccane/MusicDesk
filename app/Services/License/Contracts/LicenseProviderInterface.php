<?php

namespace App\Services\License\Contracts;

use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;

/**
 * A source of license authority (MusicDesk.tech today, Envato or authorised
 * resellers in future). Every method talks to the remote authority; none of
 * them may fabricate a successful result locally.
 *
 * Implementations throw App\Exceptions\LicenseException on any failure.
 */
interface LicenseProviderInterface
{
    /** Machine name, e.g. "musicdesk". */
    public function name(): string;

    /** Sales channel recorded against activations, e.g. "musicdesk_direct". */
    public function salesChannel(): string;

    /** Base64 Ed25519 public key used to verify this provider's signed tokens. */
    public function publicKey(): ?string;

    /** Activate this domain with a license key (re-activating returns a new token). */
    public function activate(ActivationContext $context, string $licenseKey): LicenseResult;

    /** Daily check-in with the current (possibly expired) token; returns a fresh token. */
    public function checkIn(ActivationContext $context, string $token): LicenseResult;

    /**
     * Ask the license server to email a one-time code to the license owner
     * (the email that bought / holds the license on MusicDesk.tech).
     *
     * @return array{sent_to: string, expires_in: int}
     */
    public function requestDeactivationCode(ActivationContext $context, string $token): array;

    /**
     * Release this domain from the license. A manual deactivation needs the
     * emailed code; transfers and key changes ($reason "transfer" / "key_change")
     * are followed straight away by an activation from the same installation.
     */
    public function deactivate(ActivationContext $context, string $token, ?string $code = null, string $reason = 'manual'): void;
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A licensing failure with a stable machine code (see LICENSE_API_SPEC.md).
 * Messages are safe to show to administrators and never contain the key.
 */
class LicenseException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', __('The MusicDesk licensing public key is missing or invalid, so licenses cannot be verified. Please contact MusicDesk support.'));
    }

    public static function unreachable(): self
    {
        return new self('unreachable', __('Could not reach the MusicDesk licensing service. Check your server\'s internet connection and try again.'), true);
    }

    public static function invalidResponse(): self
    {
        return new self('invalid_response', __('The licensing service returned an unexpected response. Please try again later.'), true);
    }

    public static function invalidSignature(): self
    {
        return new self('invalid_signature', __('The license token could not be verified. It was refused.'));
    }

    /**
     * Codes of the MusicDesk license server (api/license/v1) plus local ones.
     */
    public static function fromApi(string $code, ?string $message): self
    {
        $messages = [
            'invalid_key' => __('This license key is not recognised. Check it in your MusicDesk account (a key that was replaced by a new one no longer works).'),
            'invalid_domain' => __('The license server does not accept this domain. Check the application URL in Settings.'),
            'domain_limit_reached' => __('All production domains on this license are in use. Deactivate one in your MusicDesk account or buy an extra domain.'),
            'domain_in_use' => __('This domain is already active on another license.'),
            'license_pending' => __('This license is awaiting payment.'),
            'license_suspended' => __('This license has been suspended. Contact MusicDesk support.'),
            'license_expired' => __('This license has expired. Renew it from your MusicDesk account.'),
            'license_revoked' => __('This license has been revoked.'),
            'activation_not_found' => __('This installation is no longer activated. Activate it again with your license key.'),
            'activation_deactivated' => __('This domain was deactivated in your MusicDesk account. Activate it again with your license key.'),
            'invalid_token' => __('The license token was rejected. Activate again with your license key.'),
            'token_expired' => __('The license token has expired. Activate again with your license key.'),
            'domain_mismatch' => __('This license token was issued for a different domain.'),
            'rate_limited' => __('Too many licensing requests. Please wait a minute and try again.'),
            'validation_failed' => __('The license server rejected the request: :message', ['message' => strip_tags((string) $message)]),
        ];

        return new self(
            $code,
            $messages[$code] ?? ($message !== null && $message !== '' ? strip_tags($message) : __('License verification failed.')),
            in_array($code, ['rate_limited', 'server_error'], true),
        );
    }

    /**
     * Whether the server says this activation can only be recovered with the key.
     */
    public function needsReactivation(): bool
    {
        return in_array($this->errorCode, ['activation_not_found', 'invalid_token', 'token_expired', 'invalid_signature'], true);
    }
}

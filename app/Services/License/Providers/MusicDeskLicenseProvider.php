<?php

namespace App\Services\License\Providers;

use App\Exceptions\LicenseException;
use App\Services\License\Contracts\LicenseProviderInterface;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use App\Services\License\LicenseApiClient;
use App\Services\License\LicenseSignatureVerifier;

/**
 * Production license provider: the MusicDesk.tech license server
 * (https://musicdesk.tech/api/license/v1).
 *
 * Every token is verified locally with the shipped public key before it is
 * trusted; without a valid key nothing can be activated (fail closed).
 */
class MusicDeskLicenseProvider implements LicenseProviderInterface
{
    public function __construct(
        protected LicenseApiClient $client,
        protected LicenseSignatureVerifier $verifier,
    ) {}

    public function name(): string
    {
        return 'musicdesk';
    }

    public function salesChannel(): string
    {
        return 'musicdesk_direct';
    }

    public function publicKey(): ?string
    {
        $key = config('licensing.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function activate(ActivationContext $context, string $licenseKey): LicenseResult
    {
        $this->ensureConfigured();

        $data = $this->client->post('activate', [
            'key' => $licenseKey,
            'domain' => $context->domain,
            'version' => $context->version,
            'php_version' => PHP_VERSION,
            'instance_id' => $context->installationId,
        ]);

        return $this->result($data, $context);
    }

    public function checkIn(ActivationContext $context, string $token): LicenseResult
    {
        $this->ensureConfigured();

        $data = $this->client->post('check_in', [
            'token' => $token,
            'version' => $context->version,
            'php_version' => PHP_VERSION,
        ]);

        return $this->result($data, $context);
    }

    public function deactivate(ActivationContext $context, string $token): void
    {
        $this->ensureConfigured();

        $this->client->post('deactivate', ['token' => $token]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function result(array $data, ActivationContext $context): LicenseResult
    {
        $token = $data['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw LicenseException::invalidResponse();
        }

        [$payload, $signature] = LicenseSignatureVerifier::split($token);

        return $this->verifier->verify($payload, $signature, $this->publicKey(), $context, $data);
    }

    protected function ensureConfigured(): void
    {
        $key = $this->publicKey() ? base64_decode((string) $this->publicKey(), true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw LicenseException::notConfigured();
        }
    }
}

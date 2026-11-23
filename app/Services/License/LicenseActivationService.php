<?php

namespace App\Services\License;

use App\Exceptions\LicenseException;
use App\Models\LicenseActivation;
use App\Models\LicenseEvent;
use App\Models\User;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use Illuminate\Support\Facades\DB;

/**
 * Activation, offline activation, deactivation and domain transfer of this
 * installation's license.
 */
class LicenseActivationService
{
    /** LDSK-XXXX-XXXX-XXXX-XXXX (MusicDesk) and development test keys. */
    public const KEY_PATTERN = '/^[A-Z0-9]{2,6}(?:-[A-Z0-9]{2,8}){3,6}$/';

    public function __construct(
        protected LicenseManager $manager,
        protected LicenseCacheService $cache,
        protected LicenseSignatureVerifier $verifier,
    ) {}

    public static function normalizeKey(string $key): string
    {
        return strtoupper(preg_replace('/\s+/', '', $key) ?? '');
    }

    /**
     * Ask the licensing authority to activate this installation. Does not
     * write to the database (used by the installer before tables exist).
     *
     * @throws LicenseException
     */
    public function activate(ActivationContext $context, string $licenseKey, ?string $provider = null): LicenseResult
    {
        $licenseKey = self::normalizeKey($licenseKey);

        if (! preg_match(self::KEY_PATTERN, $licenseKey)) {
            throw LicenseException::fromApi('invalid_key', null);
        }

        $result = $this->manager->provider($provider ?? LicenseAuthority::defaultDriver($licenseKey))->activate($context, $licenseKey);

        if (! $result->isActive()) {
            throw LicenseException::fromApi('license_'.$result->status, null);
        }

        return $result;
    }

    /**
     * Reads a MusicDesk offline activation file (.ldlic, format "ldlic-1")
     * and verifies its signed token for this domain. Does not persist.
     *
     * @throws LicenseException
     */
    public function readOfflineFile(ActivationContext $context, string $contents, ?string $provider = null): LicenseResult
    {
        $data = json_decode($contents, true);
        if (! is_array($data) || ($data['format'] ?? null) !== 'ldlic-1' || ! is_string($data['token'] ?? null)) {
            throw new LicenseException('invalid_file', __('This is not a LabelDesk offline activation file (.ldlic).'));
        }

        [$payload, $signature] = LicenseSignatureVerifier::split($data['token']);
        $result = $this->verifier->verify($payload, $signature, $this->manager->provider($provider)->publicKey(), $context);

        if (! $result->isActive()) {
            throw LicenseException::fromApi('license_'.$result->status, null);
        }

        return $result;
    }

    /**
     * Persist a verified activation. Any previous activation is retired.
     */
    public function persist(ActivationContext $context, string $licenseKey, LicenseResult $result, ?string $provider = null, ?User $user = null, ?string $ip = null, bool $offline = false): LicenseActivation
    {
        $licenseKey = self::normalizeKey($licenseKey);
        $provider ??= LicenseAuthority::defaultDriver($licenseKey);

        return DB::transaction(function () use ($context, $licenseKey, $result, $provider, $user, $ip, $offline) {
            LicenseActivation::query()->where('status', 'active')->update([
                'status' => 'deactivated',
                'deactivated_at' => now(),
            ]);

            $activation = LicenseActivation::query()->create([
                'provider' => $provider,
                'sales_channel' => $result->salesChannel,
                'license_key' => $licenseKey,
                'license_key_hash' => hash('sha256', $licenseKey),
                'activation_id' => $result->activationId,
                'activation_secret' => null,
                'domain' => $context->domain,
                'application_url' => $context->applicationUrl,
                'installation_fingerprint' => $context->fingerprint(),
                'status' => 'active',
                'offline' => $offline,
                'activated_at' => now(),
            ]);

            $this->applyResult($activation, $result);
            $this->cache->store($activation, $result);
            $this->log($activation, $offline ? 'activated_offline' : 'activated', 'success', null, $result->message, $user, $ip);

            return $activation;
        });
    }

    /**
     * Replace the license key: the new key is activated for this domain first;
     * only when that succeeds is the old activation released on the license
     * server (best effort) and retired here. A refused key changes nothing.
     *
     * @throws LicenseException
     */
    public function changeKey(?LicenseActivation $current, ?ActivationContext $currentContext, ActivationContext $context, string $newKey, ?User $user = null, ?string $ip = null): LicenseActivation
    {
        $newKey = self::normalizeKey($newKey);
        if ($current && $current->license_key && hash_equals((string) $current->license_key_hash, hash('sha256', $newKey))) {
            throw new LicenseException('same_key', __('This key is already in use here. To pick up a renewal, use "Verify now" (or "Check again").'));
        }

        $result = $this->activate($context, $newKey);

        if ($current && $currentContext) {
            $token = $this->currentToken($current);
            if ($token !== null && ! $current->offline) {
                rescue(fn () => $this->manager->provider($current->provider)->deactivate($currentContext, $token), report: false);
            }
            $this->log($current, 'key_changed', 'success', null, __('Replaced by a key ending :last4.', ['last4' => substr($newKey, -4)]), $user, $ip);
        }

        return $this->persist($context, $newKey, $result, null, $user, $ip);
    }

    /**
     * The installation's current token (the latest stored one, even if expired).
     */
    public function currentToken(LicenseActivation $activation): ?string
    {
        $cache = $activation->caches()->orderByDesc('issued_at')->first();

        return $cache ? $cache->payload.'.'.$cache->signature : null;
    }

    /**
     * Release this domain on the license server. If the server cannot be
     * reached the local activation is kept, so a network problem never
     * leaves the customer without a license.
     *
     * @throws LicenseException
     */
    public function deactivate(LicenseActivation $activation, ActivationContext $context, ?User $user = null, ?string $ip = null): void
    {
        $token = $this->currentToken($activation);

        if ($token !== null && ! $activation->offline) {
            try {
                $this->manager->provider($activation->provider)->deactivate($context, $token);
            } catch (LicenseException $e) {
                // Already gone on the server: deactivating locally is the right outcome.
                if (! in_array($e->errorCode, ['activation_not_found', 'activation_deactivated'], true)) {
                    $this->log($activation, 'deactivated', 'failure', $e->errorCode, $e->getMessage(), $user, $ip);
                    throw $e;
                }
            }
        }

        DB::transaction(function () use ($activation, $user, $ip) {
            $activation->forceFill(['status' => 'deactivated', 'deactivated_at' => now()])->save();
            $activation->caches()->delete();
            $this->log($activation, 'deactivated', 'success', null, null, $user, $ip);
        });
    }

    /**
     * Move the license to this installation's new domain (hosting migration):
     * the old domain is released and the new one activated with the stored key.
     * If the new activation fails, the old domain is re-activated.
     *
     * @throws LicenseException
     */
    public function transfer(LicenseActivation $activation, ActivationContext $current, string $newUrl, ?User $user = null, ?string $ip = null): LicenseActivation
    {
        $key = (string) $activation->license_key;
        if ($activation->offline || $key === '') {
            throw new LicenseException('offline_activation', __('Offline activations cannot be transferred. Download a new activation file for the new domain from your MusicDesk account.'));
        }

        $oldContext = ActivationContext::for($activation->application_url, $current->installationId);
        $newContext = ActivationContext::for($newUrl, $current->installationId);
        $provider = $this->manager->provider($activation->provider);
        $token = $this->currentToken($activation);

        if ($token !== null) {
            try {
                $provider->deactivate($oldContext, $token);
            } catch (LicenseException $e) {
                if (! in_array($e->errorCode, ['activation_not_found', 'activation_deactivated'], true)) {
                    $this->log($activation, 'transferred', 'failure', $e->errorCode, $e->getMessage(), $user, $ip);
                    throw $e;
                }
            }
        }

        try {
            $result = $this->activate($newContext, $key, $activation->provider);
        } catch (LicenseException $e) {
            // Put the old domain back so the installation keeps its license.
            try {
                $restored = $provider->activate($oldContext, $key);
                $this->cache->store($activation, $restored);
            } catch (LicenseException) {
                // Nothing more we can do; the error below explains the situation.
            }
            $this->log($activation, 'transferred', 'failure', $e->errorCode, $e->getMessage(), $user, $ip);
            throw $e;
        }

        return DB::transaction(function () use ($activation, $newContext, $result, $user, $ip) {
            $activation->forceFill([
                'domain' => $newContext->domain,
                'application_url' => $newContext->applicationUrl,
                'installation_fingerprint' => $newContext->fingerprint(),
                'activation_id' => $result->activationId,
            ])->save();

            $activation->caches()->delete();
            $this->applyResult($activation, $result);
            $this->cache->store($activation, $result);
            $this->log($activation, 'transferred', 'success', null, $newContext->domain, $user, $ip);

            return $activation;
        });
    }

    public function applyResult(LicenseActivation $activation, LicenseResult $result): void
    {
        $activation->forceFill([
            'license_status' => $result->status,
            'plan' => $result->plan,
            'license_type' => $result->licenseType,
            'sales_channel' => $result->salesChannel,
            'features' => $result->features,
            'activation_limit' => $result->activationLimit,
            'activations_used' => $result->activationsUsed,
            'user_limit' => $result->userLimit,
            'expires_at' => $result->expiresAt,
            'expired_detected_at' => $result->status === 'expired' || ($result->expiresAt?->isPast() ?? false)
                ? ($activation->expired_detected_at ?? now())
                : null,
            'support_until' => $result->supportUntil,
            'updates_until' => $result->updatesUntil,
            'environment' => $result->environment,
            'max_version' => $result->maxVersion,
            'check_in_requested' => $result->checkInRequested,
            'messages' => $result->messages,
            'activation_id' => $result->activationId ?? $activation->activation_id,
            'last_verified_at' => now(),
            'last_attempt_at' => now(),
            'last_error_code' => null,
        ])->save();
    }

    public function log(?LicenseActivation $activation, string $event, string $result, ?string $code = null, ?string $message = null, ?User $user = null, ?string $ip = null): void
    {
        LicenseEvent::query()->create([
            'license_activation_id' => $activation?->id,
            'user_id' => $user?->id,
            'event' => $event,
            'result' => $result,
            'code' => $code,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'ip_address' => $ip,
        ]);
    }
}

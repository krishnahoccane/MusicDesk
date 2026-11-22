<?php

namespace App\Services\License;

use App\Enums\LicenseState;
use App\Exceptions\LicenseException;
use App\Models\LicenseActivation;
use App\Models\User;
use App\Services\Installation\InstallationState;
use App\Services\License\Data\ActivationContext;
use App\Services\License\Data\LicenseResult;
use App\Services\License\Data\LicenseSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Computes the installation's license state from the last verified signed
 * token and refreshes it with the licensing authority.
 *
 * Connectivity failures never delete or damage data: the last verified token
 * keeps working until it expires plus the offline grace period, after which
 * the application becomes read-only until a successful verification.
 */
class LicenseValidationService
{
    protected ?LicenseSnapshot $memo = null;

    public function __construct(
        protected LicenseManager $manager,
        protected LicenseCacheService $cache,
        protected LicenseActivationService $activations,
        protected InstallationState $installation,
    ) {}

    public function activation(): ?LicenseActivation
    {
        try {
            if (! Schema::hasTable('license_activations')) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return LicenseActivation::query()->where('status', 'active')->latest('id')->first();
    }

    public function contextFor(LicenseActivation $activation): ActivationContext
    {
        return ActivationContext::for($activation->application_url, (string) $this->installation->installationId());
    }

    public function snapshot(bool $fresh = false): LicenseSnapshot
    {
        if ($this->memo && ! $fresh) {
            return $this->memo;
        }

        return $this->memo = $this->evaluate();
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    protected function evaluate(): LicenseSnapshot
    {
        $activation = $this->activation();

        if (! $activation) {
            return new LicenseSnapshot(LicenseState::Unlicensed, __('No license is activated for this installation.'));
        }

        try {
            $result = $this->cache->latest($activation, $this->contextFor($activation));
        } catch (LicenseException $e) {
            return new LicenseSnapshot(LicenseState::Restricted, $e->getMessage(), null, $activation);
        }

        if (! $result) {
            return new LicenseSnapshot(LicenseState::Restricted, __('No verified license data is available. Click "Verify now" to contact MusicDesk.'), null, $activation);
        }

        // Annual licenses keep running after expiry (updates pause); pending
        // licenses work during the payment window. Anything else is refused.
        if (! in_array($result->status, LicenseResult::USABLE_STATUSES, true)) {
            return new LicenseSnapshot(LicenseState::Restricted, LicenseException::fromApi('license_'.$result->status, null)->getMessage(), $result, $activation);
        }

        if ($result->validUntil->isFuture()) {
            return new LicenseSnapshot(LicenseState::Active, null, $result, $activation);
        }

        $graceEnds = $result->validUntil->addDays((int) config('licensing.offline_grace_period', 14));

        if ($graceEnds->isFuture()) {
            return new LicenseSnapshot(
                LicenseState::GracePeriod,
                __('LabelDesk could not confirm your license recently. Restore internet access to MusicDesk.tech before :date to avoid read-only mode.', [
                    'date' => $graceEnds->setTimezone(config('labeldesk.display_timezone'))->format(config('labeldesk.date_format')),
                ]),
                $result,
                $activation,
                $graceEnds,
            );
        }

        return new LicenseSnapshot(
            LicenseState::Restricted,
            __('The offline grace period has ended. LabelDesk is read-only until your license is verified again.'),
            $result,
            $activation,
            $graceEnds,
        );
    }

    /**
     * Contact the licensing authority and store a fresh signed token.
     *
     * @throws LicenseException
     */
    public function refresh(?User $user = null, ?string $ip = null): LicenseSnapshot
    {
        $activation = $this->activation();
        if (! $activation) {
            throw new LicenseException('not_activated', __('No license is activated for this installation.'));
        }

        $activation->forceFill(['last_attempt_at' => now()])->save();
        $context = $this->contextFor($activation);
        $provider = $this->manager->provider($activation->provider);
        $token = $this->activations->currentToken($activation);
        $key = (string) $activation->license_key;

        try {
            try {
                $result = $token !== null
                    ? $provider->checkIn($context, $token)
                    : ($key !== '' ? $provider->activate($context, $key) : throw LicenseException::fromApi('activation_not_found', null));
            } catch (LicenseException $e) {
                // The server no longer knows this token: activate again with the stored key.
                if ($e->needsReactivation() && $key !== '') {
                    $result = $provider->activate($context, $key);
                } else {
                    throw $e;
                }
            }
        } catch (LicenseException $e) {
            $activation->forceFill(['last_error_code' => $e->errorCode])->save();

            // A definitive answer (suspended, revoked, domain deactivated …) is
            // recorded; network errors leave the stored token untouched.
            $definitive = str_starts_with($e->errorCode, 'license_') || $e->errorCode === 'activation_deactivated';
            if (! $e->retryable && $definitive) {
                $activation->forceFill(['license_status' => $e->errorCode === 'activation_deactivated' ? 'deactivated' : substr($e->errorCode, 8)])->save();
                $activation->caches()->delete();
            }

            $this->activations->log($activation, 'verify_failed', 'failure', $e->errorCode, $e->getMessage(), $user, $ip);
            $this->forget();
            throw $e;
        }

        $this->activations->applyResult($activation, $result);
        $this->cache->store($activation, $result);
        $this->activations->log($activation, 'verified', 'success', null, $result->message, $user, $ip);

        return $this->snapshot(fresh: true);
    }

    /**
     * Refresh in the background when the cached token is older than the
     * configured cache duration. Safe to call on every request: rate-limited
     * by a cache lock and silent on failure.
     */
    public function refreshIfStale(): void
    {
        $activation = $this->activation();
        if (! $activation) {
            return;
        }

        $last = $activation->last_attempt_at ? CarbonImmutable::parse($activation->last_attempt_at) : null;
        $requested = $activation->check_in_requested && (! $last || $last->addMinutes(15)->isPast());
        if (! $requested && $last && $last->addHours((int) config('licensing.cache_duration', 24))->isFuture()) {
            return;
        }

        Cache::lock('labeldesk:license-refresh', 120)->get(function () {
            try {
                $this->refresh();
            } catch (Throwable) {
                // Offline or rejected — the snapshot reflects it on next request.
            }
        });
    }
}

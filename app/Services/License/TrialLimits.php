<?php

namespace App\Services\License;

use App\Exceptions\TrialLimitException;
use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Free trials (MusicDesk.tech 2.0.1): while the verified license token says `trial: true`,
 * its `limits` cap how many users, artists, releases and tracks may exist. Existing records stay
 * usable; only creating one more is refused. Limits are lifted at once when a check-in returns
 * `trial: false` (the trial was bought). Domains are enforced by the license server.
 */
class TrialLimits
{
    public const RESOURCES = ['users', 'artists', 'releases', 'tracks'];

    public function __construct(protected LicenseValidationService $validation) {}

    public function isTrial(): bool
    {
        return (bool) $this->validation->snapshot()->result?->trial;
    }

    /** @return array{domains: ?int, users: ?int, artists: ?int, releases: ?int, tracks: ?int, addons: bool}|null */
    public function limits(): ?array
    {
        $result = $this->validation->snapshot()->result;

        return $result?->trial ? ($result->limits ?? ['domains' => null, 'users' => null, 'artists' => null, 'releases' => null, 'tracks' => null, 'addons' => false]) : null;
    }

    public function limit(string $resource): ?int
    {
        return $this->limits()[$resource] ?? null;
    }

    /** Add-ons during a trial only when the trial includes them. */
    public function addonsAllowed(): bool
    {
        $limits = $this->limits();

        return $limits === null || $limits['addons'];
    }

    public function plan(): string
    {
        return (string) ($this->validation->snapshot()->result?->plan ?? '');
    }

    public function planName(): string
    {
        $plan = $this->plan();

        return $plan === '' || $plan === 'default' ? (string) config('labeldesk.product.name') : config('labeldesk.product.name').' '.ucfirst($plan);
    }

    public function buyUrl(): string
    {
        $plan = $this->plan();

        return LicenseAuthority::CHECKOUT_URL.($plan !== '' && $plan !== 'default' ? '/'.rawurlencode($plan) : '');
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->isTrial() ? $this->validation->snapshot()->result?->expiresAt : null;
    }

    /** Whole days left, rounded up (0 once ended). */
    public function daysLeft(): ?int
    {
        $ends = $this->endsAt();
        if (! $ends) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInSeconds($ends, false) / 86400));
    }

    /** Last 3 days (status `expiring`), for a stronger banner. */
    public function ending(): bool
    {
        $days = $this->daysLeft();

        return $this->isTrial() && ($this->validation->snapshot()->result?->status === 'expiring' || ($days !== null && $days <= 3));
    }

    /** What exists now (deleted records do not count; the artist-account scope is ignored). */
    public function count(string $resource): int
    {
        return match ($resource) {
            'users' => User::query()->where('is_active', true)->count(),
            'artists' => Artist::query()->withoutGlobalScope('artist-account')->count(),
            'releases' => Release::query()->withoutGlobalScope('artist-account')->count(),
            'tracks' => Track::query()->withoutGlobalScope('artist-account')->count(),
            default => 0,
        };
    }

    public function allows(string $resource, int $adding = 1): bool
    {
        $limit = $this->limit($resource);

        return $limit === null || $this->count($resource) + $adding <= $limit;
    }

    /** @throws TrialLimitException */
    public function ensure(string $resource, int $adding = 1): void
    {
        if (! $this->allows($resource, $adding)) {
            throw new TrialLimitException($this->message($resource), $this->buyUrl());
        }
    }

    public function message(string $resource): string
    {
        $limit = (int) $this->limit($resource);
        $what = match ($resource) {
            'users' => trans_choice('active user|active users', $limit),
            'artists' => trans_choice('artist|artists', $limit),
            'releases' => trans_choice('release|releases', $limit),
            'tracks' => trans_choice('track|tracks', $limit),
            default => $resource,
        };

        return __('Your free trial allows up to :limit :what. Buy :plan to add more: :url', [
            'limit' => $limit, 'what' => $what, 'plan' => $this->planName(), 'url' => $this->buyUrl(),
        ]);
    }

    /** For Settings → License: limit and current use per resource. @return array<string, array{limit: ?int, used: int}> */
    public function usage(): array
    {
        return collect(self::RESOURCES)->mapWithKeys(fn ($r) => [$r => ['limit' => $this->limit($r), 'used' => $this->count($r)]])->all();
    }
}

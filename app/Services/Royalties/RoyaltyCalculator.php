<?php

namespace App\Services\Royalties;

use App\Enums\ArtistRole;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Exceptions\CatalogException;
use App\Models\ArtistContract;
use App\Models\Release;
use App\Models\RoyaltyEarning;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Models\Track;
use App\Services\SettingsService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Turns matched sales lines into per-artist earnings.
 *
 *  1. Lines are summed exactly (BCMath) per track/release and sale date.
 *  2. Revenue is split equally between the primary artists of the track
 *     (or of the release when only the UPC matched); the last artist takes
 *     the rounding remainder so nothing is lost.
 *  3. Each artist's portion is split by the artist share of the royalty
 *     contract in force on the sale date (recording, distribution or license
 *     agreement, active or since expired); without one, the default artist
 *     share from the royalty settings applies. Label amount = remainder.
 */
class RoyaltyCalculator
{
    public const DEFAULT_SHARE_SETTING = 'royalties.default_artist_share';

    public const FALLBACK_SHARE = '50';

    /** @var array<string, list<int>> */
    protected array $artistCache = [];

    /** @var array<string, ArtistContract|null> */
    protected array $contractCache = [];

    public function __construct(protected SettingsService $settings) {}

    public function defaultShare(): string
    {
        $value = (string) $this->settings->get(self::DEFAULT_SHARE_SETTING, self::FALLBACK_SHARE);

        return preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) && bccomp($value, '100', 4) <= 0 ? $value : self::FALLBACK_SHARE;
    }

    /**
     * Rebuilds the earnings of an open period.
     *
     * @return array{lines: int, unallocated: string}
     */
    public function recalculate(RoyaltyPeriod $period): array
    {
        if ($period->isLocked()) {
            throw new CatalogException(__('The period :name is locked and cannot be recalculated.', ['name' => $period->name]));
        }

        $this->artistCache = [];
        $this->contractCache = [];
        $defaultShare = $this->defaultShare();

        // 1. Exact sums per (track, release, sale date).
        $groups = [];
        $lineCount = 0;
        foreach (RoyaltyLine::query()->where('royalty_period_id', $period->id)->where('match_status', 'matched')
            ->select(['id', 'track_id', 'release_id', 'sale_date', 'units', 'net_amount_base'])->lazyById(2000) as $line) {
            $lineCount++;
            $key = ($line->track_id ?? '').'|'.($line->release_id ?? '').'|'.$line->sale_date?->toDateString();
            $groups[$key] ??= ['track_id' => $line->track_id, 'release_id' => $line->release_id, 'date' => $line->sale_date?->toDateString() ?? $period->ends_on->toDateString(), 'units' => 0, 'amount' => '0'];
            $groups[$key]['units'] += $line->units;
            $groups[$key]['amount'] = Decimal::add($groups[$key]['amount'], (string) $line->net_amount_base);
        }

        // 2–3. Split per artist and contract, aggregated per earning row.
        $earnings = [];
        $unallocated = '0';

        foreach ($groups as $group) {
            $artists = $this->artistsFor($group['track_id'], $group['release_id']);
            if ($artists === []) {
                $unallocated = Decimal::add($unallocated, $group['amount']);

                continue;
            }

            $count = count($artists);
            $portion = Decimal::div($group['amount'], (string) $count);
            $allocated = '0';
            $unitsEach = intdiv($group['units'], $count);

            foreach ($artists as $i => $artistId) {
                $isLast = $i === $count - 1;
                $gross = $isLast ? Decimal::sub($group['amount'], $allocated) : $portion;
                $allocated = Decimal::add($allocated, $gross);
                $units = $isLast ? $group['units'] - $unitsEach * ($count - 1) : $unitsEach;

                $contract = $this->contractFor($artistId, $group['date']);
                $share = $contract?->artist_share !== null ? (string) $contract->artist_share : $defaultShare;
                $artistAmount = Decimal::percentOf($gross, $share);
                $labelAmount = Decimal::sub($gross, $artistAmount);

                $key = implode('|', [$artistId, $group['track_id'] ?? '', $group['release_id'] ?? '', $contract?->id ?? '', $share]);
                $earnings[$key] ??= [
                    'royalty_period_id' => $period->id,
                    'artist_id' => $artistId,
                    'track_id' => $group['track_id'],
                    'release_id' => $group['release_id'],
                    'artist_contract_id' => $contract?->id,
                    'units' => 0,
                    'revenue' => '0',
                    'artist_share' => bcadd($share, '0', 4),
                    'artist_amount' => '0',
                    'label_amount' => '0',
                ];
                $earnings[$key]['units'] += $units;
                $earnings[$key]['revenue'] = Decimal::add($earnings[$key]['revenue'], $gross);
                $earnings[$key]['artist_amount'] = Decimal::add($earnings[$key]['artist_amount'], $artistAmount);
                $earnings[$key]['label_amount'] = Decimal::add($earnings[$key]['label_amount'], $labelAmount);
            }
        }

        DB::transaction(function () use ($period, $earnings) {
            RoyaltyEarning::query()->where('royalty_period_id', $period->id)->delete();
            foreach (array_chunk(array_values($earnings), 500) as $chunk) {
                RoyaltyEarning::query()->insert($chunk);
            }
        });

        return ['lines' => $lineCount, 'unallocated' => $unallocated];
    }

    /**
     * Primary artists of the track, else of the release.
     *
     * @return list<int>
     */
    protected function artistsFor(?int $trackId, ?int $releaseId): array
    {
        $key = ($trackId ?? '').'|'.($releaseId ?? '');

        return $this->artistCache[$key] ??= (function () use ($trackId, $releaseId) {
            if ($trackId) {
                $ids = Track::query()->find($trackId)?->artists()
                    ->wherePivot('role', ArtistRole::Primary->value)->pluck('artists.id')->all() ?? [];
                if ($ids) {
                    return array_map('intval', $ids);
                }
                $releaseId ??= Track::query()->whereKey($trackId)->value('release_id');
            }
            if ($releaseId) {
                $release = Release::withTrashed()->find($releaseId);

                return array_map('intval', $release?->artists()->wherePivot('role', ArtistRole::Primary->value)->pluck('artists.id')->all() ?? []);
            }

            return [];
        })();
    }

    protected function contractFor(int $artistId, string $date): ?ArtistContract
    {
        $key = $artistId.'|'.$date;

        return $this->contractCache[$key] ??= ArtistContract::query()
            ->where('artist_id', $artistId)
            ->whereIn('type', [ContractType::Recording->value, ContractType::Distribution->value, ContractType::Licensing->value])
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Expired->value])
            ->whereNotNull('artist_share')
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }
}

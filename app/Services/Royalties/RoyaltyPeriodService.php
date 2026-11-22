<?php

namespace App\Services\Royalties;

use App\Enums\LedgerEntryType;
use App\Enums\RoyaltyImportStatus;
use App\Exceptions\CatalogException;
use App\Models\ArtistLedgerEntry;
use App\Models\RoyaltyEarning;
use App\Models\RoyaltyPeriod;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

class RoyaltyPeriodService
{
    public function __construct(
        protected ActivityLogger $activity,
        protected RoyaltyCalculator $calculator,
    ) {}

    /**
     * @param  array{name: string, starts_on: string, ends_on: string, notes?: ?string}  $data
     */
    public function create(User $actor, array $data): RoyaltyPeriod
    {
        $overlap = RoyaltyPeriod::query()
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->value('name');
        if ($overlap) {
            throw new CatalogException(__('These dates overlap the period :name.', ['name' => $overlap]));
        }

        $period = RoyaltyPeriod::query()->create([
            'name' => trim($data['name']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ]);

        $this->activity->log('royalty.period_created', __('Created royalty period :name', ['name' => $period->name]), $period);

        return $period;
    }

    public function delete(User $actor, RoyaltyPeriod $period): void
    {
        if ($period->isLocked() || $period->imports()->exists()) {
            throw new CatalogException(__('Only empty, open periods can be deleted.'));
        }

        $period->delete();
        $this->activity->log('royalty.period_deleted', __('Deleted royalty period :name', ['name' => $period->name]), null);
    }

    /**
     * Freezes the period and posts each artist's earnings to their account.
     * Locking cannot be undone; corrections are made with adjustments.
     *
     * @return int number of artists credited
     */
    public function lock(User $actor, RoyaltyPeriod $period): int
    {
        if ($period->isLocked()) {
            throw new CatalogException(__('This period is already locked.'));
        }
        $today = now()->setTimezone(config('labeldesk.display_timezone'))->startOfDay();
        if ($period->ends_on->copy()->startOfDay()->gte($today)) {
            throw new CatalogException(__('The period runs until :date. It can be locked from the day after it ends, once all statements for it are in.', ['date' => Format::date($period->ends_on)]));
        }
        if ($period->imports()->whereIn('status', [RoyaltyImportStatus::Queued->value, RoyaltyImportStatus::Processing->value, RoyaltyImportStatus::Uploaded->value])->exists()) {
            throw new CatalogException(__('Finish or revert the imports that are still being mapped or processed first.'));
        }

        $this->calculator->recalculate($period);

        $credited = DB::transaction(function () use ($actor, $period) {
            /** @var RoyaltyPeriod $locked */
            $locked = RoyaltyPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($locked->isLocked()) {
                throw new CatalogException(__('This period is already locked.'));
            }

            $totals = [];
            foreach (RoyaltyEarning::query()->where('royalty_period_id', $period->id)->select(['artist_id', 'artist_amount'])->cursor() as $earning) {
                $totals[$earning->artist_id] = Decimal::add($totals[$earning->artist_id] ?? '0', (string) $earning->artist_amount);
            }

            foreach ($totals as $artistId => $amount) {
                if (Decimal::isZero($amount)) {
                    continue;
                }
                $entry = new ArtistLedgerEntry([
                    'type' => LedgerEntryType::Earnings,
                    'amount' => $amount,
                    'entry_date' => $period->ends_on->toDateString(),
                    'description' => __('Royalties for :period', ['period' => $period->name]),
                    'royalty_period_id' => $period->id,
                ]);
                $entry->artist_id = $artistId;
                $entry->created_by = $actor->id;
                $entry->save();
            }

            $locked->forceFill(['status' => 'locked', 'locked_at' => now(), 'locked_by' => $actor->id])->save();

            return count($totals);
        });

        $period->refresh();
        $this->activity->log('royalty.period_locked', __('Locked royalty period :name and posted earnings to :count artists', ['name' => $period->name, 'count' => $credited]), $period);

        return $credited;
    }

    /**
     * Totals for a period, summed exactly.
     *
     * @return array{revenue: string, artist: string, label: string, artists: int}
     */
    public static function totals(RoyaltyPeriod $period): array
    {
        $revenue = '0';
        $artist = '0';
        $label = '0';
        $artists = [];
        foreach (RoyaltyEarning::query()->where('royalty_period_id', $period->id)->select(['artist_id', 'revenue', 'artist_amount', 'label_amount'])->cursor() as $e) {
            $revenue = Decimal::add($revenue, (string) $e->revenue);
            $artist = Decimal::add($artist, (string) $e->artist_amount);
            $label = Decimal::add($label, (string) $e->label_amount);
            $artists[$e->artist_id] = true;
        }

        return ['revenue' => $revenue, 'artist' => $artist, 'label' => $label, 'artists' => count($artists)];
    }
}

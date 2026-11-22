<?php

namespace App\Services\Royalties;

use App\Enums\LedgerEntryType;
use App\Exceptions\CatalogException;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

/**
 * An artist's royalty account. The balance is the exact sum of all entries:
 * positive means the label owes the artist; negative means advances or
 * expenses are still to be recouped from future earnings.
 */
class ArtistAccountService
{
    public function __construct(protected ActivityLogger $activity) {}

    public static function balance(Artist|int $artist, ?string $before = null): string
    {
        $id = $artist instanceof Artist ? $artist->id : $artist;

        return Decimal::sum(ArtistLedgerEntry::query()
            ->where('artist_id', $id)
            ->when($before, fn ($q) => $q->whereDate('entry_date', '<', $before))
            ->pluck('amount')
            ->map(fn ($a) => (string) $a));
    }

    /**
     * Records a manual entry. $amount is entered as a positive number; the
     * type decides the sign (advances, expenses and payments reduce the
     * balance; adjustments use $direction "credit" or "debit").
     *
     * @param  array{type: string, amount: string, entry_date: string, description: string, method?: ?string, reference?: ?string, direction?: ?string}  $data
     */
    public function record(User $actor, Artist $artist, array $data): ArtistLedgerEntry
    {
        $type = LedgerEntryType::from($data['type']);
        if ($type === LedgerEntryType::Earnings) {
            throw new CatalogException(__('Earnings are posted automatically when a royalty period is locked.'));
        }

        $amount = Decimal::round((string) $data['amount'], 6);
        if (Decimal::compare($amount, '0') <= 0) {
            throw new CatalogException(__('Enter an amount greater than zero.'));
        }

        $signed = $type->isDeduction() || ($type === LedgerEntryType::Adjustment && ($data['direction'] ?? 'credit') === 'debit')
            ? bcmul($amount, '-1', 6)
            : $amount;

        $entry = DB::transaction(function () use ($actor, $artist, $type, $signed, $amount, $data) {
            // Serialise concurrent payments for the same artist.
            Artist::query()->whereKey($artist->id)->lockForUpdate()->first();

            if ($type === LedgerEntryType::Payment) {
                $balance = self::balance($artist);
                if (Decimal::compare($amount, $balance) > 0) {
                    throw new CatalogException(__('The payment of :amount is more than the balance owed (:balance). Record an advance instead if you are paying ahead of earnings.', [
                        'amount' => Format::money($amount),
                        'balance' => Format::money($balance),
                    ]));
                }
            }

            $entry = new ArtistLedgerEntry([
                'type' => $type,
                'amount' => $signed,
                'entry_date' => $data['entry_date'],
                'description' => trim($data['description']),
                'method' => filled($data['method'] ?? null) ? trim($data['method']) : null,
                'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
            ]);
            $entry->artist_id = $artist->id;
            $entry->created_by = $actor->id;
            $entry->save();

            return $entry;
        });

        $this->activity->log('royalty.ledger_'.$type->value, __(':type of :amount recorded for :artist', [
            'type' => $type->label(), 'amount' => Format::money($amount), 'artist' => $artist->name,
        ]), $artist, ['amount' => $signed, 'reference' => $entry->reference]);

        return $entry;
    }

    /**
     * Cancels a manual entry with an opposite adjustment (entries are never
     * edited or deleted).
     */
    public function reverse(User $actor, ArtistLedgerEntry $entry): ArtistLedgerEntry
    {
        if ($entry->type === LedgerEntryType::Earnings) {
            throw new CatalogException(__('Earnings cannot be reversed. Record an adjustment instead.'));
        }

        $marker = 'reversal:'.$entry->id;
        if (str_starts_with((string) $entry->reference, 'reversal:') || ArtistLedgerEntry::query()->where('reference', $marker)->exists()) {
            throw new CatalogException(__('This entry has already been reversed.'));
        }

        $reversal = new ArtistLedgerEntry([
            'type' => LedgerEntryType::Adjustment,
            'amount' => bcmul((string) $entry->amount, '-1', 6),
            'entry_date' => now()->setTimezone(config('labeldesk.display_timezone'))->toDateString(),
            'description' => __('Reversal of: :description', ['description' => $entry->description]),
            'reference' => $marker,
        ]);
        $reversal->artist_id = $entry->artist_id;
        $reversal->created_by = $actor->id;
        $reversal->save();

        $this->activity->log('royalty.ledger_reversed', __('Reversed a :type entry for :artist', ['type' => strtolower($entry->type->label()), 'artist' => $entry->artist->name]), $entry->artist);

        return $reversal;
    }
}

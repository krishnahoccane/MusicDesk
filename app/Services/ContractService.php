<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\ArtistContract;
use App\Models\User;
use App\Services\Activity\ActivityLogger;

/**
 * Artist contracts. Amounts are validated as decimal strings by the caller
 * and stored as DECIMAL — never converted to floats.
 */
class ContractService
{
    public function __construct(protected ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, Artist $artist, ?ArtistContract $contract, array $data): ArtistContract
    {
        $contract ??= $artist->contracts()->make();
        abort_unless($contract->artist_id === $artist->id, 404);

        $created = ! $contract->exists;
        $contract->fill([
            'title' => trim($data['title']),
            'type' => $data['type'],
            'status' => $data['status'],
            'start_date' => $data['start_date'] ?: null,
            'end_date' => $data['end_date'] ?: null,
            'artist_share' => self::decimalOrNull($data['artist_share'] ?? null),
            'advance_amount' => self::decimalOrNull($data['advance_amount'] ?? null),
            'currency' => filled($data['advance_amount'] ?? null) ? ($data['currency'] ?: config('labeldesk.currency')) : null,
            'territory' => filled($data['territory'] ?? null) ? trim($data['territory']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ]);

        if ($created) {
            $contract->created_by = $actor->id;
        }

        $dirty = array_keys($contract->getDirty());
        $contract->save();

        $this->activity->log(
            $created ? 'contract.created' : 'contract.updated',
            $created
                ? __('Added contract ":title" for :artist', ['title' => $contract->title, 'artist' => $artist->name])
                : __('Updated contract ":title" for :artist', ['title' => $contract->title, 'artist' => $artist->name]),
            $contract,
            $created ? ['status' => $contract->status->value] : ['fields' => array_values(array_diff($dirty, ['updated_at']))],
        );

        return $contract;
    }

    public function delete(User $actor, Artist $artist, ArtistContract $contract): void
    {
        abort_unless($contract->artist_id === $artist->id, 404);

        // Soft delete; linked documents stay attached to the artist.
        $contract->delete();

        $this->activity->log('contract.deleted', __('Deleted contract ":title" for :artist', ['title' => $contract->title, 'artist' => $artist->name]), $contract);
    }

    public static function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_replace(',', '', trim((string) $value));
    }
}

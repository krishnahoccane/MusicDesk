<?php

namespace App\Support;

use App\Exceptions\CatalogException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Data boundary for artist accounts.
 *
 * When the signed-in user is an artist account, models that belong to an
 * artist (artists, releases, tracks, deliveries, contracts, documents,
 * earnings, ledger entries, contributors) only return rows of that
 * account's linked artists — see App\Models\Concerns\ScopedToArtistAccount.
 * Route model binding goes through the same scopes, so other records
 * answer 404. Staff, console commands and queued jobs are not affected.
 */
final class ArtistAccess
{
    /**
     * Linked artist ids when the current user is an artist account, else null (no limit).
     *
     * @return list<int>|null
     */
    public static function ids(): ?array
    {
        if (! Auth::hasUser()) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User && $user->isArtistAccount() ? $user->linkedArtistIds() : null;
    }

    public static function restricted(): bool
    {
        return self::ids() !== null;
    }

    public static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->isArtistAccount() ? $user : null;
    }

    /**
     * Artist accounts may only credit their own artists.
     *
     * @param  iterable<int|string>  $artistIds
     */
    public static function ensureOwned(iterable $artistIds): void
    {
        $allowed = self::ids();
        if ($allowed === null) {
            return;
        }

        foreach ($artistIds as $id) {
            if ((int) $id > 0 && ! in_array((int) $id, $allowed, true)) {
                throw new CatalogException(__('You can only credit your own artist profiles.'));
            }
        }
    }
}

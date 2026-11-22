<?php

namespace App\Models\Concerns;

use App\Support\ArtistAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits queries to the signed-in artist account's own artists.
 * The model defines how a row belongs to an artist.
 */
trait ScopedToArtistAccount
{
    public static function bootScopedToArtistAccount(): void
    {
        static::addGlobalScope('artist-account', function (Builder $query) {
            $ids = ArtistAccess::ids();
            if ($ids !== null) {
                $query->getModel()->scopeToArtists($query, $ids);
            }
        });
    }

    /**
     * @param  list<int>  $artistIds
     */
    abstract public function scopeToArtists(Builder $query, array $artistIds): void;
}

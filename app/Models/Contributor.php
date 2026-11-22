<?php

namespace App\Models;

use App\Models\Concerns\ScopedToArtistAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A songwriter, producer, engineer or other credited person. Credits on
 * tracks are TrackContributor rows (one per role).
 */
class Contributor extends Model
{
    use ScopedToArtistAccount;

    /** @var list<string> */
    protected $fillable = ['name', 'legal_name', 'ipi_number', 'pro', 'email', 'notes'];

    public function credits(): HasMany
    {
        return $this->hasMany(TrackContributor::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q->where('name', 'like', $like)
            ->orWhere('legal_name', 'like', $like)
            ->orWhere('ipi_number', 'like', $like));
    }

    /**
     * Artist accounts: only rows of their own artists.
     */
    public function scopeToArtists(Builder $query, array $artistIds): void
    {
        $query->where(fn (Builder $q) => $q->where($this->qualifyColumn('created_by'), auth()->id())->orWhereHas('credits'));
    }
}

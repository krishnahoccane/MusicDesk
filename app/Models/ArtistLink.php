<?php

namespace App\Models;

use App\Enums\LinkPlatform;
use App\Models\Concerns\ScopedToArtistAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistLink extends Model
{
    use ScopedToArtistAccount;

    /** @var list<string> */
    protected $fillable = ['platform', 'url'];

    protected function casts(): array
    {
        return ['platform' => LinkPlatform::class];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Artist accounts: only rows of their own artists.
     */
    public function scopeToArtists(Builder $query, array $artistIds): void
    {
        $query->whereIn($this->qualifyColumn('artist_id'), $artistIds);
    }
}

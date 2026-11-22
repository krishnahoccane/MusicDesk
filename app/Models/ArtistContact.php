<?php

namespace App\Models;

use App\Enums\ContactRole;
use App\Models\Concerns\ScopedToArtistAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistContact extends Model
{
    use ScopedToArtistAccount;

    /** @var list<string> */
    protected $fillable = ['name', 'role', 'email', 'phone', 'notes', 'is_primary'];

    protected function casts(): array
    {
        return [
            'role' => ContactRole::class,
            'is_primary' => 'boolean',
        ];
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

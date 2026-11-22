<?php

namespace App\Models;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Models\Concerns\ScopedToArtistAccount;
use App\Support\Countries;
use Database\Factories\ArtistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Artist extends Model
{
    /** @use HasFactory<ArtistFactory> */
    use HasFactory, SoftDeletes;

    use ScopedToArtistAccount;

    /** @var list<string> */
    protected $fillable = [
        'name', 'legal_name', 'type', 'status', 'country', 'primary_genre',
        'email', 'phone', 'bio', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ArtistType::class,
            'status' => ArtistStatus::class,
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ArtistContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ArtistLink::class)->orderBy('platform');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(ArtistContract::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArtistDocument::class);
    }

    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class, 'release_artists')->withPivot(['role', 'position']);
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_artists')->withPivot(['role', 'position']);
    }

    /**
     * Artists credited on any release or track cannot be deleted, so credits
     * and royalty history stay intact.
     */
    public function isCredited(): bool
    {
        return $this->releases()->withTrashed()->exists() || $this->tracks()->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

            return collect($parts)->filter()->take(2)
                ->map(fn (string $p) => Str::upper(Str::substr($p, 0, 1)))
                ->implode('') ?: 'A';
        });
    }

    protected function countryName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->country ? Countries::name($this->country) : null);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('legal_name', 'like', $like)
            ->orWhere('email', 'like', $like));
    }

    /**
     * Artist accounts: only rows of their own artists.
     */
    public function scopeToArtists(Builder $query, array $artistIds): void
    {
        $query->whereIn($this->qualifyColumn('id'), $artistIds);
    }
}

<?php

namespace App\Models;

use App\Enums\ArtistRole;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'title', 'version', 'type', 'upc', 'catalog_number', 'label_name', 'c_line', 'p_line',
        'release_date', 'original_release_date', 'genre_id', 'language_id', 'explicit', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'type' => 'single',
        'explicit' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ReleaseType::class,
            'status' => ReleaseStatus::class,
            'release_date' => 'date',
            'original_release_date' => 'date',
            'explicit' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'release_artists')
            ->withPivot(['role', 'position'])
            ->orderByPivot('position')
            ->withTrashed();
    }

    public function primaryArtists(): BelongsToMany
    {
        return $this->artists()->wherePivot('role', ArtistRole::Primary->value);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('disc_number')->orderBy('position');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReleaseDelivery::class);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ReleaseStatusHistory::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isEditable(): bool
    {
        return ! $this->trashed() && $this->status->isEditable();
    }

    public function displayTitle(): string
    {
        return $this->version ? "{$this->title} ({$this->version})" : $this->title;
    }

    /**
     * "Artist A & Artist B feat. Artist C" from the loaded artists relation.
     */
    public function artistLine(): string
    {
        $artists = $this->relationLoaded('artists') ? $this->artists : $this->artists()->get();

        return self::formatArtistLine($artists);
    }

    /**
     * @param  iterable<Artist>  $artists  with pivot role
     */
    public static function formatArtistLine(iterable $artists): string
    {
        $primary = [];
        $featuring = [];
        foreach ($artists as $artist) {
            $artist->pivot?->role === ArtistRole::Featuring->value ? $featuring[] = $artist->name : $primary[] = $artist->name;
        }

        $line = implode(' & ', $primary);
        if ($featuring) {
            $line .= ($line !== '' ? ' ' : '').'feat. '.implode(', ', $featuring);
        }

        return $line;
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('upc', 'like', $like)
            ->orWhere('catalog_number', 'like', $like)
            ->orWhereHas('artists', fn (Builder $a) => $a->where('name', 'like', $like)));
    }
}

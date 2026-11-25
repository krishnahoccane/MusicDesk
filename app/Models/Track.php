<?php

namespace App\Models;

use App\Enums\ArtistRole;
use App\Models\Concerns\ScopedToArtistAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recording on a release. The master audio file is private and is only
 * streamed or downloaded through authorised routes.
 */
class Track extends Model
{
    use ScopedToArtistAccount;

    /** @var list<string> */
    protected $fillable = ['title', 'version', 'isrc', 'duration_seconds', 'explicit', 'language_id', 'mood_id', 'crbt_name', 'crbt_start_seconds', 'is_draft', 'disc_number', 'position'];

    /** Storage location is never exposed. */
    protected $hidden = ['audio_path'];

    protected function casts(): array
    {
        return [
            'explicit' => 'boolean',
            'is_draft' => 'boolean',
            'crbt_start_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'audio_size' => 'integer',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'track_artists')
            ->withPivot(['role', 'position'])
            ->orderByPivot('position')
            ->withTrashed();
    }

    public function credits(): HasMany
    {
        return $this->hasMany(TrackContributor::class)->orderBy('role');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function mood(): BelongsTo
    {
        return $this->belongsTo(Mood::class);
    }

    /** Ring-back tone start as m:ss ('' when not set). */
    public function formattedCrbtStart(): string
    {
        return $this->crbt_start_seconds === null ? '' : sprintf('%d:%02d', intdiv($this->crbt_start_seconds, 60), $this->crbt_start_seconds % 60);
    }

    public function displayTitle(): string
    {
        return $this->version ? "{$this->title} ({$this->version})" : $this->title;
    }

    public function hasAudio(): bool
    {
        return $this->audio_path !== null;
    }

    public function formattedDuration(): string
    {
        if ($this->duration_seconds === null) {
            return '—';
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }

    /**
     * ISRC shown in its conventional hyphenated form: CC-XXX-YY-NNNNN.
     */
    public function formattedIsrc(): ?string
    {
        if (! $this->isrc || strlen($this->isrc) !== 12) {
            return $this->isrc;
        }

        return substr($this->isrc, 0, 2).'-'.substr($this->isrc, 2, 3).'-'.substr($this->isrc, 5, 2).'-'.substr($this->isrc, 7);
    }

    public function artistLine(): string
    {
        $artists = $this->relationLoaded('artists') ? $this->artists : $this->artists()->get();

        return Release::formatArtistLine($artists);
    }

    public function hasPrimaryArtist(): bool
    {
        return $this->artists()->wherePivot('role', ArtistRole::Primary->value)->exists();
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $isrc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term) ?? '');

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->when($isrc !== '', fn (Builder $w) => $w->orWhere('isrc', 'like', '%'.$isrc.'%'))
            ->orWhereHas('artists', fn (Builder $a) => $a->where('name', 'like', $like)));
    }

    /**
     * Artist accounts: only rows of their own artists.
     */
    public function scopeToArtists(Builder $query, array $artistIds): void
    {
        $query->whereHas('release');
    }
}

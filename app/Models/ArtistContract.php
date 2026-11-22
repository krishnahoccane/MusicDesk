<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Concerns\ScopedToArtistAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A contract between the label and an artist. Monetary values are DECIMAL
 * strings (never floats); artist_share is a percentage 0–100.
 */
class ArtistContract extends Model
{
    use ScopedToArtistAccount;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'title', 'type', 'status', 'start_date', 'end_date',
        'artist_share', 'advance_amount', 'currency', 'territory', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContractType::class,
            'status' => ContractStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'artist_share' => 'decimal:4',
            'advance_amount' => 'decimal:6',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArtistDocument::class);
    }

    public static function today(): Carbon
    {
        return now()->setTimezone(config('labeldesk.display_timezone'))->startOfDay();
    }

    /**
     * Status as it applies today: an "active" contract past its end date is
     * shown as expired without anyone having to update it.
     */
    public function effectiveStatus(): ContractStatus
    {
        if ($this->status === ContractStatus::Active && $this->end_date && $this->end_date->lt(self::today())) {
            return ContractStatus::Expired;
        }

        return $this->status;
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query->where('status', ContractStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', self::today()->toDateString()));
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        $today = self::today();

        return $query->where('status', ContractStatus::Active->value)
            ->whereBetween('end_date', [$today->toDateString(), $today->copy()->addDays($days)->toDateString()]);
    }

    /**
     * Label share of royalties, derived from the artist share.
     */
    public function labelShare(): ?string
    {
        return $this->artist_share === null ? null : bcsub('100', (string) $this->artist_share, 4);
    }

    /**
     * Artist accounts: only rows of their own artists.
     */
    public function scopeToArtists(Builder $query, array $artistIds): void
    {
        $query->whereIn($this->qualifyColumn('artist_id'), $artistIds);
    }
}

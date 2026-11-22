<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in an artist's royalty account. Entries are never edited; mistakes
 * are corrected with an adjustment so the history stays auditable.
 */
class ArtistLedgerEntry extends Model
{
    /** @var list<string> */
    protected $fillable = ['type', 'amount', 'entry_date', 'description', 'method', 'reference', 'royalty_period_id'];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount' => 'decimal:6',
            'entry_date' => 'date',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class)->withTrashed();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RoyaltyPeriod::class, 'royalty_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}

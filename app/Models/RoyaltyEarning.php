<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calculated earnings for one artist from one track (or release) in a
 * period, under one contract. Rebuilt whenever an open period is recalculated.
 */
class RoyaltyEarning extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'revenue' => 'decimal:6',
            'artist_share' => 'decimal:4',
            'artist_amount' => 'decimal:6',
            'label_amount' => 'decimal:6',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RoyaltyPeriod::class, 'royalty_period_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class)->withTrashed();
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ArtistContract::class, 'artist_contract_id')->withTrashed();
    }
}

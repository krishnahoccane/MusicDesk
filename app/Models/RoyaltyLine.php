<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sales row from an imported statement.
 */
class RoyaltyLine extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'units' => 'integer',
            'net_amount' => 'decimal:6',
            'net_amount_base' => 'decimal:6',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(RoyaltyImport::class, 'royalty_import_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }
}

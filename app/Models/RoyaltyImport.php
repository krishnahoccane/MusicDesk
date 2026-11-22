<?php

namespace App\Models;

use App\Enums\RoyaltyImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded sales statement. The file is kept privately for the audit trail.
 */
class RoyaltyImport extends Model
{
    /** @var list<string> */
    protected $fillable = ['source_name', 'currency', 'exchange_rate', 'decimal_separator'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'uploaded'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'status' => RoyaltyImportStatus::class,
            'column_map' => 'array',
            'exchange_rate' => 'decimal:8',
            'net_total' => 'decimal:6',
            'net_total_base' => 'decimal:6',
            'processed_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RoyaltyPeriod::class, 'royalty_period_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RoyaltyImportProfile::class, 'royalty_import_profile_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RoyaltyLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}

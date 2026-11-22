<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An accounting period (e.g. "2026 Q4"). Open periods accept imports; a
 * locked period is frozen and has posted its earnings to artist accounts.
 */
class RoyaltyPeriod extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'starts_on', 'ends_on', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function imports(): HasMany
    {
        return $this->hasMany(RoyaltyImport::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RoyaltyLine::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(RoyaltyEarning::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by')->withTrashed();
    }
}

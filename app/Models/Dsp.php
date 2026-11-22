<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A digital service provider (store / streaming service).
 */
class Dsp extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'website', 'lead_time_days', 'delivery_notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lead_time_days' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReleaseDelivery::class);
    }
}

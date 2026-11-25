<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Track mood. `value` is the exact text distributors expect (the PDL COSMOS mood
 * list, including its own spellings); `name` is what LabelDesk shows.
 */
class Mood extends Model
{
    /** The PDL COSMOS mood list (data ingestion API guide), value => name shown. */
    public const PDL = [
        'Romantic' => 'Romantic', 'Happy' => 'Happy', 'Sad' => 'Sad', 'Dance' => 'Dance', 'Bhangra' => 'Bhangra',
        'Partiotic' => 'Patriotic', 'Nostalgic' => 'Nostalgic', 'Inspirational' => 'Inspirational',
        'Enthusiastic' => 'Enthusiastic', 'Optimistic' => 'Optimistic', 'Passion' => 'Passion',
        'Pessimistic' => 'Pessimistic', 'Spiritual' => 'Spiritual', 'Peppy' => 'Peppy',
        'Philosophical' => 'Philosophical', 'Mellow' => 'Mellow', 'Calm' => 'Calm',
    ];

    protected $fillable = ['name', 'value', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }
}

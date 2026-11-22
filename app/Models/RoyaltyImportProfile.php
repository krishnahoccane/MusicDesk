<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saved column mapping for a statement source (e.g. "Distributor X monthly").
 */
class RoyaltyImportProfile extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'column_map', 'decimal_separator', 'currency'];

    protected function casts(): array
    {
        return ['column_map' => 'array'];
    }
}

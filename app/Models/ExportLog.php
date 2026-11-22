<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'report', 'format', 'filters', 'row_count', 'ip_address'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

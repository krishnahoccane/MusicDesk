<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkUpload extends Model
{
    protected $fillable = ['user_id', 'type', 'original_name', 'total_rows', 'created_count', 'skipped_count', 'errors'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

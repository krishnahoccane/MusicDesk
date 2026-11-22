<?php

namespace App\Models;

use App\Enums\ReleaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every workflow transition of a release.
 */
class ReleaseStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['from_status', 'to_status', 'user_id', 'comment'];

    protected function casts(): array
    {
        return [
            'from_status' => ReleaseStatus::class,
            'to_status' => ReleaseStatus::class,
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

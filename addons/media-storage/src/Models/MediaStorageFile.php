<?php

namespace LabelDesk\Addons\MediaStorage\Models;

use App\Models\Release;
use App\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaStorageFile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'delete_local_at' => 'datetime',
            'local_deleted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'size' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MediaStorageTarget::class, 'target_id');
    }
}

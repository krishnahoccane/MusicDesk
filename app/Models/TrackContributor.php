<?php

namespace App\Models;

use App\Enums\ContributorRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackContributor extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['contributor_id', 'role', 'share'];

    protected function casts(): array
    {
        return [
            'role' => ContributorRole::class,
            'share' => 'decimal:4',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(Contributor::class);
    }
}

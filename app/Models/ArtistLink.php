<?php

namespace App\Models;

use App\Enums\LinkPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistLink extends Model
{
    /** @var list<string> */
    protected $fillable = ['platform', 'url'];

    protected function casts(): array
    {
        return ['platform' => LinkPlatform::class];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}

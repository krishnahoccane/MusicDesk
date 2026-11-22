<?php

namespace App\Models;

use App\Enums\ContactRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistContact extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'role', 'email', 'phone', 'notes', 'is_primary'];

    protected function casts(): array
    {
        return [
            'role' => ContactRole::class,
            'is_primary' => 'boolean',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}

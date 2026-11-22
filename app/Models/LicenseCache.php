<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed license token. Trusted only after its Ed25519 signature has been
 * verified; editing a row simply invalidates the license.
 */
class LicenseCache extends Model
{
    protected $table = 'license_cache';

    protected $fillable = ['license_activation_id', 'payload', 'signature', 'issued_at', 'valid_until'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function activation(): BelongsTo
    {
        return $this->belongsTo(LicenseActivation::class, 'license_activation_id');
    }
}

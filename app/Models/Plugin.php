<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An installed add-on. Its files live in plugins/<slug>; the manifest is a
 * copy of the signed plugin.json.
 */
class Plugin extends Model
{
    protected $fillable = ['slug', 'name', 'version', 'addon', 'description', 'manifest', 'active', 'installed_at', 'activated_at', 'last_error',
        'license_key', 'license_token', 'license_status', 'license_expires_at', 'license_checked_at', 'license_error'];

    protected $hidden = ['license_key', 'license_token'];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'active' => 'boolean',
            'installed_at' => 'datetime',
            'activated_at' => 'datetime',
            'license_key' => 'encrypted',
            'license_expires_at' => 'datetime',
            'license_checked_at' => 'datetime',
        ];
    }

    public function maskedKey(): ?string
    {
        $key = (string) $this->license_key;

        return $key === '' ? null : str_repeat('•', 4).'-'.substr($key, -4);
    }
}

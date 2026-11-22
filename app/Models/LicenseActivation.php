<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LicenseActivation extends Model
{
    protected $fillable = [
        'provider', 'sales_channel', 'license_key', 'license_key_hash', 'activation_id',
        'activation_secret', 'domain', 'application_url', 'installation_fingerprint', 'status',
        'license_status', 'plan', 'license_type', 'features', 'activation_limit', 'activations_used',
        'user_limit', 'expires_at', 'support_until', 'updates_until', 'activated_at',
        'last_verified_at', 'last_attempt_at', 'last_error_code', 'deactivated_at',
        'environment', 'max_version', 'check_in_requested', 'messages', 'offline', 'expired_detected_at',
    ];

    protected $hidden = ['license_key', 'activation_secret', 'license_key_hash'];

    protected function casts(): array
    {
        return [
            'license_key' => 'encrypted',
            'activation_secret' => 'encrypted',
            'features' => 'array',
            'expires_at' => 'datetime',
            'expired_detected_at' => 'datetime',
            'support_until' => 'datetime',
            'updates_until' => 'datetime',
            'activated_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'check_in_requested' => 'boolean',
            'messages' => 'array',
            'offline' => 'boolean',
        ];
    }

    public function caches(): HasMany
    {
        return $this->hasMany(LicenseCache::class);
    }

    public function latestCache(): HasOne
    {
        return $this->hasOne(LicenseCache::class)->latestOfMany('issued_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class);
    }

    /**
     * Masked key for display, e.g. MD-LD-••••-••••-••••-7F2K.
     */
    public function maskedKey(): string
    {
        return self::mask((string) $this->license_key);
    }

    public static function mask(string $key): string
    {
        if ($key === '') {
            return __('Offline activation file');
        }

        $parts = explode('-', $key);
        if (count($parts) < 3) {
            return str_repeat('•', max(4, strlen($key) - 4)).substr($key, -4);
        }

        // Keep product prefixes (LDSK, MD-LD) and the last group; hide the rest.
        $last = array_pop($parts);
        $prefix = [];
        while ($parts !== [] && count($prefix) < 2 && in_array($parts[0], ['LDSK', 'MD', 'LD'], true)) {
            $prefix[] = array_shift($parts);
        }
        $hidden = array_fill(0, count($parts), '••••');

        return implode('-', array_merge($prefix, $hidden, [$last]));
    }
}

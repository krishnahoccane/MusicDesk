<?php

namespace LabelDesk\Addons\IncomingWebhooks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One incoming webhook URL: /api/hooks/{token}. Requests must be signed
 * with the endpoint's secret (HMAC-SHA256, see SignatureVerifier).
 *
 * @property int $id
 * @property string $name
 * @property string $token
 * @property string $action
 * @property string $secret
 * @property ?string $previous_secret
 * @property ?Carbon $previous_secret_expires_at
 * @property int $tolerance_seconds
 * @property ?array<int, string> $allowed_ips
 * @property ?array<string, mixed> $settings
 * @property bool $is_active
 * @property ?int $acting_user_id
 */
class WebhookEndpoint extends Model
{
    /** How long the old secret keeps working after a rotation. */
    public const ROTATION_GRACE_HOURS = 24;

    /** @var list<string> */
    protected $fillable = ['name', 'action', 'tolerance_seconds', 'allowed_ips', 'settings', 'is_active', 'acting_user_id'];

    /** @var list<string> */
    protected $hidden = ['secret', 'previous_secret'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'tolerance_seconds' => 300];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'allowed_ips' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'tolerance_seconds' => 'integer',
            'last_delivery_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public static function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }

    public function url(): string
    {
        return url('/api/hooks/'.$this->token);
    }

    /**
     * Secrets a signature may be made with: the current one and, during
     * the grace period after a rotation, the previous one.
     *
     * @return list<string>
     */
    public function acceptedSecrets(): array
    {
        $secrets = [(string) $this->secret];
        if ($this->previous_secret && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = (string) $this->previous_secret;
        }

        return $secrets;
    }

    public function hasPreviousSecret(): bool
    {
        return $this->previous_secret !== null && (bool) $this->previous_secret_expires_at?->isFuture();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function actingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_user_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}

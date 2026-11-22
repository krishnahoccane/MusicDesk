<?php

namespace LabelDesk\Addons\IncomingWebhooks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request received by an endpoint (or an admin replay of one).
 *
 * Statuses: queued → processing → processed | failed; rejected (refused
 * before processing: signature, IP, size, JSON, disabled); duplicate
 * (event id seen before, not processed again).
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property ?string $event_id
 * @property string $status
 * @property ?int $http_status
 * @property bool $signature_valid
 * @property ?array<string, string> $headers
 * @property ?string $payload
 * @property int $payload_size
 * @property bool $payload_truncated
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property int $attempts
 * @property ?int $replay_of_id
 */
class WebhookDelivery extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const DUPLICATE = 'duplicate';

    /** Bytes of the body kept in the log. Larger payloads are processed but stored truncated (and cannot be replayed). */
    public const PAYLOAD_LIMIT = 65536;

    /** Days delivery log entries are kept. */
    public const RETENTION_DAYS = 30;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'result' => 'array',
            'signature_valid' => 'boolean',
            'payload_truncated' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::QUEUED => __('Queued'),
            self::PROCESSING => __('Processing'),
            self::PROCESSED => __('Processed'),
            self::FAILED => __('Failed'),
            self::REJECTED => __('Rejected'),
            self::DUPLICATE => __('Duplicate'),
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    public function statusVariant(): ?string
    {
        return match ($this->status) {
            self::PROCESSED => 'success',
            self::FAILED, self::REJECTED => 'danger',
            self::QUEUED, self::PROCESSING => 'info',
            default => null,
        };
    }

    /** Only authentic, complete payloads can be processed again. */
    public function canReplay(): bool
    {
        return $this->signature_valid && ! $this->payload_truncated && $this->payload !== null
            && in_array($this->status, [self::PROCESSED, self::FAILED, self::DUPLICATE], true);
    }

    /** @return array<string, mixed>|null */
    public function decodedPayload(): ?array
    {
        $data = json_decode((string) $this->payload, true);

        return is_array($data) ? $data : null;
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function replayOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replay_of_id');
    }

    public function replayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replayed_by')->withTrashed();
    }
}

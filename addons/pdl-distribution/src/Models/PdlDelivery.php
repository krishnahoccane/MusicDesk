<?php

namespace LabelDesk\Addons\PdlDistribution\Models;

use App\Models\Release;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A release at PDL (one COSMOS album). `stage` is where the ingestion is
 * (internal); `status` is what the label sees. `ingest` holds the ingestion
 * token and the presigned upload URLs, encrypted, and is never shown.
 *
 * @property array<string, mixed>|null $ingest
 */
class PdlDelivery extends Model
{
    public const STATUSES = [
        'queued' => ['Waiting to send', null],
        'sending' => ['Sending', 'info'],
        'failed' => ['Needs attention', 'danger'],
        'ingested' => ['At PDL, not submitted', 'warning'],
        'submitted' => ['Submitted, PDL to review', 'info'],
        'in_review' => ['Resubmitted, PDL to review', 'info'],
        'issue_reported' => ['Issue reported by PDL', 'danger'],
        'approved' => ['Approved by PDL', 'accent'],
        'released' => ['Delivered to stores', 'accent'],
        'live' => ['Live', 'success'],
        'release_error' => ['Store delivery error', 'danger'],
        'taken_down' => ['Taken down', null],
    ];

    /** Statuses whose state can still change at PDL: synced periodically. */
    public const SYNCED = ['submitted', 'in_review', 'issue_reported', 'approved', 'released', 'release_error', 'ingested'];

    protected $fillable = [
        'release_id', 'action', 'stage', 'status', 'platforms', 'allow_any_go_live_date', 'mood', 'pdl_album_id', 'pdl_upc',
        'ingest', 'payload', 'pdl_state', 'issue', 'attempts', 'next_attempt_at', 'last_error', 'requested_by',
        'queued_at', 'submitted_at', 'live_at', 'last_synced_at',
    ];

    /** Never serialised to the browser. */
    protected $hidden = ['ingest', 'payload'];

    protected function casts(): array
    {
        return [
            'allow_any_go_live_date' => 'boolean',
            'ingest' => 'encrypted:array',
            'pdl_state' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'live_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PdlDeliveryEvent::class)->latest('id');
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status][0] ?? $this->status);
    }

    public function statusVariant(): ?string
    {
        return self::STATUSES[$this->status][1] ?? null;
    }

    /** Work for the background task is pending (queued, mid-way or waiting for a retry). */
    public function isInProgress(): bool
    {
        return in_array($this->stage, ['queued', 'metadata', 'uploading', 'verifying', 'submitting', 'updating'], true)
            && $this->status !== 'failed';
    }

    /** PDL accepted the album for review (submit confirmed). */
    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function note(string $message, string $level = 'info', ?int $userId = null): void
    {
        $this->events()->create(['level' => $level, 'message' => mb_substr($message, 0, 1000), 'user_id' => $userId, 'created_at' => now()]);
    }
}

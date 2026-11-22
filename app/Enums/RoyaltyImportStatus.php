<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RoyaltyImportStatus: string
{
    use HasOptions;

    case Uploaded = 'uploaded';     // file stored, columns not mapped yet
    case Queued = 'queued';         // waiting for the queue worker
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Reverted = 'reverted';     // lines removed; record kept for the audit trail

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => __('Needs column mapping'),
            self::Queued => __('Waiting to be processed'),
            self::Processing => __('Processing'),
            self::Completed => __('Imported'),
            self::Failed => __('Failed'),
            self::Reverted => __('Reverted'),
        };
    }

    public function variant(): ?string
    {
        return match ($this) {
            self::Uploaded, self::Queued => 'info',
            self::Processing => 'accent',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Reverted => null,
        };
    }

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }
}

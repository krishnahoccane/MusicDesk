<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Release approval workflow:
 *
 *   draft ──submit──► submitted ──start review──► in_review ──approve──► approved ──► ready
 *     ▲                   │                           │
 *     └──── withdraw ─────┘          reject ◄─────────┘ (also from submitted)
 *   rejected ──submit──► submitted          approved/ready ──reopen──► draft
 *
 * Content (release details, artwork, tracks) can only change in draft or rejected.
 */
enum ReleaseStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Submitted => __('Submitted'),
            self::InReview => __('In review'),
            self::Approved => __('Approved'),
            self::Rejected => __('Changes requested'),
            self::Ready => __('Ready for delivery'),
        };
    }

    public function variant(): ?string
    {
        return match ($this) {
            self::Draft => null,
            self::Submitted, self::InReview => 'info',
            self::Approved => 'accent',
            self::Rejected => 'warning',
            self::Ready => 'success',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}

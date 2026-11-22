<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Status of a release at one store:
 *
 *   pending ──deliver──► delivered ──go live──► live
 *      │                    │                    │
 *      └──reject──► rejected ◄──reject──┘        └──request takedown──► takedown_requested
 *                     │                                   │          │
 *                     └──re-deliver──► delivered          │          └──cancel──► live
 *                                                         └──confirm──► taken_down ──re-deliver──► delivered
 */
enum DeliveryStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Delivered = 'delivered';
    case Live = 'live';
    case Rejected = 'rejected';
    case TakedownRequested = 'takedown_requested';
    case TakenDown = 'taken_down';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Delivered => __('Delivered'),
            self::Live => __('Live'),
            self::Rejected => __('Rejected by store'),
            self::TakedownRequested => __('Takedown requested'),
            self::TakenDown => __('Taken down'),
        };
    }

    public function variant(): ?string
    {
        return match ($this) {
            self::Pending => null,
            self::Delivered => 'info',
            self::Live => 'success',
            self::Rejected => 'danger',
            self::TakedownRequested => 'warning',
            self::TakenDown => null,
        };
    }

    /**
     * Statuses in which the release is (or may still be) available at the
     * store. While any delivery is in one of these, the release cannot be
     * reopened for editing.
     */
    public function isActiveAtStore(): bool
    {
        return in_array($this, [self::Pending, self::Delivered, self::Live, self::TakedownRequested], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isActiveAtStore())));
    }
}

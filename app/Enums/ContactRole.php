<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContactRole: string
{
    use HasOptions;

    case Artist = 'artist';
    case Manager = 'manager';
    case BookingAgent = 'booking_agent';
    case Lawyer = 'lawyer';
    case Publicist = 'publicist';
    case Accountant = 'accountant';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Artist => __('Artist (direct)'),
            self::Manager => __('Manager'),
            self::BookingAgent => __('Booking agent'),
            self::Lawyer => __('Lawyer'),
            self::Publicist => __('Publicist'),
            self::Accountant => __('Accountant'),
            self::Other => __('Other'),
        };
    }
}

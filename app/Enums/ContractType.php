<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContractType: string
{
    use HasOptions;

    case Recording = 'recording';
    case Distribution = 'distribution';
    case Licensing = 'licensing';
    case Publishing = 'publishing';
    case Management = 'management';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Recording => __('Recording agreement'),
            self::Distribution => __('Distribution agreement'),
            self::Licensing => __('License agreement'),
            self::Publishing => __('Publishing agreement'),
            self::Management => __('Management agreement'),
            self::Other => __('Other'),
        };
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ArtistStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Prospect = 'prospect';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Prospect => __('Prospect'),
            self::Inactive => __('Inactive'),
        };
    }

    public function variant(): ?string
    {
        return match ($this) {
            self::Active => 'success',
            self::Prospect => 'info',
            self::Inactive => null,
        };
    }
}

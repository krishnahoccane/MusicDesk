<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How an artist is credited on a release or track.
 */
enum ArtistRole: string
{
    use HasOptions;

    case Primary = 'primary';
    case Featuring = 'featuring';
    case Remixer = 'remixer';

    public function label(): string
    {
        return match ($this) {
            self::Primary => __('Primary artist'),
            self::Featuring => __('Featuring'),
            self::Remixer => __('Remixer'),
        };
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ArtistType: string
{
    use HasOptions;

    case Solo = 'solo';
    case Band = 'band';
    case Duo = 'duo';
    case Producer = 'producer';
    case Dj = 'dj';
    case Composer = 'composer';
    case Ensemble = 'ensemble';

    public function label(): string
    {
        return match ($this) {
            self::Solo => __('Solo artist'),
            self::Band => __('Band'),
            self::Duo => __('Duo'),
            self::Producer => __('Producer'),
            self::Dj => __('DJ'),
            self::Composer => __('Composer'),
            self::Ensemble => __('Ensemble / orchestra'),
        };
    }
}

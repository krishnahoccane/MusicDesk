<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReleaseType: string
{
    use HasOptions;

    case Single = 'single';
    case Ep = 'ep';
    case Album = 'album';
    case Compilation = 'compilation';

    public function label(): string
    {
        return match ($this) {
            self::Single => __('Single'),
            self::Ep => __('EP'),
            self::Album => __('Album'),
            self::Compilation => __('Compilation'),
        };
    }
}

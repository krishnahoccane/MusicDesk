<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContributorRole: string
{
    use HasOptions;

    case Composer = 'composer';
    case Lyricist = 'lyricist';
    case Songwriter = 'songwriter';
    case Arranger = 'arranger';
    case Producer = 'producer';
    case MixingEngineer = 'mixing_engineer';
    case MasteringEngineer = 'mastering_engineer';
    case Performer = 'performer';
    case Publisher = 'publisher';

    public function label(): string
    {
        return match ($this) {
            self::Composer => __('Composer'),
            self::Lyricist => __('Lyricist'),
            self::Songwriter => __('Songwriter'),
            self::Arranger => __('Arranger'),
            self::Producer => __('Producer'),
            self::MixingEngineer => __('Mixing engineer'),
            self::MasteringEngineer => __('Mastering engineer'),
            self::Performer => __('Performer / musician'),
            self::Publisher => __('Publisher'),
        };
    }

    /**
     * Roles whose splits are royalty-bearing (publishing); shares for these
     * should add up to 100% per role.
     */
    public function hasShare(): bool
    {
        return in_array($this, [self::Composer, self::Lyricist, self::Songwriter, self::Publisher], true);
    }
}

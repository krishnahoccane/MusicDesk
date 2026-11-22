<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DocumentCategory: string
{
    use HasOptions;

    case Contract = 'contract';
    case Identity = 'identity';
    case Tax = 'tax';
    case Press = 'press';
    case Artwork = 'artwork';
    case Correspondence = 'correspondence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => __('Contract'),
            self::Identity => __('Identity document'),
            self::Tax => __('Tax form'),
            self::Press => __('Press kit'),
            self::Artwork => __('Artwork / photo'),
            self::Correspondence => __('Correspondence'),
            self::Other => __('Other'),
        };
    }
}

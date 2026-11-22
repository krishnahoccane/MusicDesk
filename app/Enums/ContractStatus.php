<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContractStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Terminated => __('Terminated'),
        };
    }

    public function variant(): ?string
    {
        return match ($this) {
            self::Draft => 'info',
            self::Active => 'success',
            self::Expired => 'warning',
            self::Terminated => 'danger',
        };
    }
}

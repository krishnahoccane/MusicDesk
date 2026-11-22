<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Artist account entries. Amounts are signed: positive = owed to the
 * artist, negative = paid to the artist or recoupable from future earnings.
 */
enum LedgerEntryType: string
{
    use HasOptions;

    case Earnings = 'earnings';       // created automatically when a period is locked
    case Advance = 'advance';         // recoupable, negative
    case Expense = 'expense';         // recoupable, negative
    case Payment = 'payment';         // negative
    case Adjustment = 'adjustment';   // positive or negative

    public function label(): string
    {
        return match ($this) {
            self::Earnings => __('Royalty earnings'),
            self::Advance => __('Advance (recoupable)'),
            self::Expense => __('Recoupable expense'),
            self::Payment => __('Payment'),
            self::Adjustment => __('Adjustment'),
        };
    }

    /**
     * Types staff can record by hand.
     *
     * @return array<string, string>
     */
    public static function manualOptions(): array
    {
        return collect([self::Advance, self::Expense, self::Payment, self::Adjustment])
            ->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }

    /** Whether the entered amount is stored as a deduction. */
    public function isDeduction(): bool
    {
        return in_array($this, [self::Advance, self::Expense, self::Payment], true);
    }
}

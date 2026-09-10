<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of discount a coupon gives — CLAUDE.md §5.4.
 *
 * Derived from which value column is set rather than stored alongside them.
 * Storing it too would give two sources for one fact, and the first time an
 * edit changed one and not the other the discount would compute from a
 * percentage that is no longer there.
 */
enum CouponType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage off',
            self::Fixed => 'Fixed amount off',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Percent => 'info',
            self::Fixed => 'success',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

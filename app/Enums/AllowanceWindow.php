<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The period an allowance resets over — CLAUDE.md §5.5.
 *
 * The difference is substantial and easy to skim past: "4 live webinars per
 * month" on a yearly plan is 48 a year; "4 per term" is 4. The predecessor
 * summed all-time purchases and so never reset an allowance at all, which is
 * the failure this column exists to make impossible.
 */
enum AllowanceWindow: string
{
    /** Once per billing term — the whole year on a yearly plan. */
    case Term = 'term';

    /** Resets every month, whatever the billing period. */
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Term => 'Per billing term',
            self::Month => 'Per month',
        };
    }

    /** How it reads inside an allowance summary — "4 live per month". */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Term => 'per term',
            self::Month => 'per month',
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

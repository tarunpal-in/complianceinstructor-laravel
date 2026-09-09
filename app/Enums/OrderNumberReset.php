<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * How often the order number's sequence returns to 1.
 *
 * This is the half of the format that is easy to miss. "2024-10-05-001" only
 * makes sense if the counter resets daily — otherwise the first order on the
 * 6th is 002 and the number stops meaning anything. Conversely a daily reset
 * with no date in the format produces a collision on the second day, which is
 * why the two settings are validated against each other rather than offered
 * independently.
 */
enum OrderNumberReset: string
{
    case Never = 'never';
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never — one continuous sequence',
            self::Daily => 'Every day',
            self::Monthly => 'Every month',
            self::Yearly => 'Every year',
        };
    }

    /**
     * The key the counter is stored under.
     *
     * Two orders share a counter exactly when they share this key, so it is
     * also the definition of "the same period".
     */
    public function periodKey(Carbon $at): string
    {
        return match ($this) {
            self::Never => 'all',
            self::Daily => $at->format('Y-m-d'),
            self::Monthly => $at->format('Y-m'),
            self::Yearly => $at->format('Y'),
        };
    }

    /**
     * The date tokens a format must carry for this reset to stay unique.
     *
     * Each inner array is a set of alternatives, and every set must be
     * satisfied. A monthly reset needs the month *and* the year — otherwise
     * October 2024 and October 2025 both produce "10-001" — and a daily reset
     * needs all three.
     *
     * @return array<int, array<int, string>>
     */
    public function requiredTokens(): array
    {
        $year = ['YYYY', 'YY'];

        return match ($this) {
            self::Never => [],
            self::Yearly => [$year],
            self::Monthly => [$year, ['MM']],
            self::Daily => [$year, ['MM'], ['DD']],
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

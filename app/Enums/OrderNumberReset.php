<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * How often the order number's sequence returns to 1.
 *
 * "Year" means the *accounting* year, which is not necessarily the calendar
 * one — a business running 1 April to 31 March wants the sequence to restart in
 * April, and every order between April and the following March to carry the
 * same year. The start month is a setting; see OrderNumberFormat.
 *
 * There is no daily option. A per-day sequence is a lot of numbering for a
 * catalogue that sells tens of orders a day, and it forces the whole date into
 * the reference whether the client wants it there or not.
 */
enum OrderNumberReset: string
{
    case Never = 'never';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never — one continuous sequence',
            self::Monthly => 'Every month',
            self::Yearly => 'Every accounting year',
        };
    }

    /**
     * The year an order falls in, given when the accounting year starts.
     *
     * With an April start, 10 February 2025 belongs to the year that began in
     * April 2024, so this returns 2024. With a January start it is simply the
     * calendar year.
     */
    public static function accountingYear(Carbon $at, int $startMonth): int
    {
        return $at->month >= $startMonth ? $at->year : $at->year - 1;
    }

    /**
     * The key the counter is stored under.
     *
     * Two orders share a counter exactly when they share this key, so it is
     * also the definition of "the same period".
     */
    public function periodKey(Carbon $at, int $startMonth = 1): string
    {
        return match ($this) {
            self::Never => 'all',
            self::Monthly => 'm'.$at->format('Y-m'),
            self::Yearly => 'y'.self::accountingYear($at, $startMonth),
        };
    }

    /**
     * The tokens a format must carry for this reset to stay unique.
     *
     * Each inner array is a set of alternatives, and every set must be
     * satisfied. A monthly reset needs the month *and* the year — otherwise
     * October 2024 and October 2025 both produce "10-001".
     *
     * @return array<int, array<int, string>>
     */
    public function requiredTokens(): array
    {
        $year = ['YYYY', 'YY', 'FY'];

        return match ($this) {
            self::Never => [],
            self::Yearly => [$year],
            self::Monthly => [$year, ['MM']],
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

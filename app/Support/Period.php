<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * A stretch of time a report covers, and the one before it.
 *
 * Reports are read as "how did we do", which is always a comparison, so every
 * period knows how to produce the one before it. That is the only reason this
 * is a class rather than two dates passed around.
 *
 * **Everything here works in the business timezone, not the app's.** The app
 * runs on UTC (config/app.php), which is right for storage and wrong for a
 * question like "what did we take today" — a US business asking that at 8pm on
 * the 3rd would be shown the 4th. The boundaries are computed in the business
 * timezone and converted back to UTC for the query, so the numbers match what
 * somebody sees on a calendar and the database still stores UTC throughout.
 */
final class Period
{
    /**
     * Where the business is, for the purposes of "today".
     *
     * §1 has the audience as US professionals. Eastern is the default because
     * a wrong timezone here does not error, it silently misreports a day — and
     * a default of UTC would be wrong for this client on every evening order.
     */
    private const DEFAULT_TIMEZONE = 'America/New_York';

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly ?string $preset = null,
    ) {}

    /**
     * The ranges somebody actually asks for.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'last_7' => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'mtd' => 'This month',
            'last_month' => 'Last month',
            'qtd' => 'This quarter',
            'ytd' => 'This year',
            'last_year' => 'Last year',
        ];
    }

    public static function preset(string $key): self
    {
        $now = self::now();

        [$from, $to] = match ($key) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            // Inclusive of today, so "last 7 days" is a week ending now rather
            // than a week ending yesterday — which is what somebody means when
            // they open it at lunchtime.
            'last_7' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_30' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'mtd' => [$now->startOfMonth(), $now->endOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'qtd' => [$now->startOfQuarter(), $now->endOfDay()],
            'ytd' => [$now->startOfYear(), $now->endOfDay()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => throw new InvalidArgumentException("Unknown period [{$key}]."),
        };

        return new self($from, $to, self::options()[$key] ?? $key, $key);
    }

    /** A range somebody typed. Dates, not times — the day boundaries are ours. */
    public static function between(string $from, string $to): self
    {
        $start = CarbonImmutable::parse($from, self::timezone())->startOfDay();
        $end = CarbonImmutable::parse($to, self::timezone())->endOfDay();

        // Swapped rather than refused: somebody picking the dates in the wrong
        // order meant the range between them, and an error here would be
        // pedantry rather than protection.
        if ($end->lessThan($start)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self(
            $start,
            $end,
            $start->isSameDay($end)
                ? $start->format('j M Y')
                : $start->format('j M Y').' – '.$end->format('j M Y'),
        );
    }

    /**
     * The period immediately before this one, for the comparison figure.
     *
     * Calendar presets step back by calendar, so "this month" compares against
     * last month rather than against the 30 days before it — otherwise a
     * 31-day month is reported as growth against a 30-day one. Everything else
     * steps back by its own length.
     */
    public function previous(): self
    {
        if ($this->preset === 'mtd') {
            $start = $this->from->subMonthNoOverflow();

            return new self($start, $start->endOfMonth(), 'Last month');
        }

        if ($this->preset === 'last_month') {
            $start = $this->from->subMonthNoOverflow();

            return new self($start, $start->endOfMonth(), 'The month before');
        }

        if ($this->preset === 'ytd' || $this->preset === 'last_year') {
            $start = $this->from->subYear();

            return new self($start, $start->endOfYear(), 'Last year');
        }

        if ($this->preset === 'qtd') {
            $start = $this->from->subQuarterNoOverflow();

            return new self($start, $start->endOfQuarter(), 'Last quarter');
        }

        $length = $this->lengthInSeconds();

        return new self(
            $this->from->subSeconds($length),
            $this->from->subSecond(),
            'The '.$this->describeLength().' before',
        );
    }

    /** How the range reads in a heading. */
    public function describe(): string
    {
        return $this->label;
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->endOfDay()) + 1;
    }

    /**
     * Whether the range is short enough to be worth breaking down by day.
     *
     * A year broken down by day is 365 rows nobody reads; by month it is
     * twelve. The threshold is arbitrary and the point is only that the report
     * picks one rather than asking.
     */
    public function grouping(): string
    {
        return match (true) {
            $this->days() <= 62 => 'day',
            $this->days() <= 180 => 'week',
            default => 'month',
        };
    }

    /** Where the business is. Reports ask "today" and mean their today. */
    public static function timezone(): DateTimeZone
    {
        $name = Setting::get('reports.timezone', self::DEFAULT_TIMEZONE);

        try {
            return new DateTimeZone(is_string($name) ? $name : self::DEFAULT_TIMEZONE);
        } catch (Exception) {
            // A mistyped setting must not take every report down with it.
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    private function lengthInSeconds(): int
    {
        return (int) $this->from->diffInSeconds($this->to);
    }

    private function describeLength(): string
    {
        $days = $this->days();

        return $days === 1 ? 'day' : $days.' days';
    }
}

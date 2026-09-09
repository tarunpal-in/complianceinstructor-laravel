<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderNumberReset;
use App\Models\Order;
use App\Models\OrderNumberSequence;
use App\Models\Setting;
use App\Support\OrderNumberFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next order number.
 *
 * §4 rule 2 — "Controllers route; Actions do". One place takes a number, so
 * there is one place where the counter can be advanced twice or not at all.
 *
 * P3 calls this inside the order transaction. Three things keep it safe: the
 * counter row is locked for the duration, numbers already in use are skipped,
 * and `orders.number` is UNIQUE as the final backstop.
 */
class GenerateOrderNumber
{
    /**
     * Enough to step over a period's worth of already-issued numbers without
     * spinning if something is badly wrong.
     */
    private const MAX_SKIPS = 1000;

    public function __invoke(?Carbon $at = null): string
    {
        $at ??= now();
        $format = self::format();

        // A misconfigured format would mint colliding numbers, so it falls back
        // rather than taking the settings on trust. The settings screen refuses
        // to save an invalid one, which makes this reachable only by editing the
        // row directly.
        if (! $format->isValid()) {
            $format = new OrderNumberFormat(
                OrderNumberFormat::DEFAULT_PATTERN,
                4,
                OrderNumberReset::Yearly,
            );
        }

        $periodKey = $format->reset->periodKey($at, $format->yearStartMonth);

        return DB::transaction(function () use ($format, $periodKey, $at): string {
            $sequence = OrderNumberSequence::query()
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first()
                ?? OrderNumberSequence::create(['period_key' => $periodKey, 'next_value' => 1]);

            $value = $sequence->next_value;

            /*
             * Skip numbers that already exist.
             *
             * Changing when the accounting year starts moves dates between
             * years: switch to an April start in February and today's orders
             * belong to the previous year, whose counter begins at 1 while
             * numbers carrying that year have already been issued. Rather than
             * forbid the change or fail the checkout, step over what is taken.
             */
            $number = $format->render($value, $at);

            for ($skips = 0; $skips < self::MAX_SKIPS && self::isTaken($number); $skips++) {
                $value++;
                $number = $format->render($value, $at);
            }

            $sequence->update(['next_value' => $value + 1]);

            return $number;
        });
    }

    /** Soft-deleted orders included: a number on an invoice is never reissued. */
    private static function isTaken(string $number): bool
    {
        return Order::withTrashed()->where('number', $number)->exists();
    }

    /** The configured format, or the default where nothing is set. */
    public static function format(): OrderNumberFormat
    {
        return new OrderNumberFormat(
            (string) Setting::get('orders.number_format', OrderNumberFormat::DEFAULT_PATTERN),
            (int) Setting::get('orders.number_padding', 4),
            OrderNumberReset::tryFrom((string) Setting::get('orders.number_reset', 'yearly'))
                ?? OrderNumberReset::Yearly,
            (int) Setting::get('orders.year_start_month', 1),
        );
    }

    /**
     * What the next order will be numbered, without taking the number.
     *
     * The settings preview needs this; nothing else should, because reading it
     * and then writing an order is exactly the race the lock above removes.
     */
    public static function peek(?Carbon $at = null): int
    {
        $at ??= now();
        $format = self::format();

        return (int) (OrderNumberSequence::query()
            ->where('period_key', $format->reset->periodKey($at, $format->yearStartMonth))
            ->value('next_value') ?? 1);
    }

    /**
     * Set where the sequence resumes from — used when importing orders that
     * already carry numbers from the old site.
     */
    public static function setNext(int $value, ?Carbon $at = null): void
    {
        $at ??= now();
        $format = self::format();

        OrderNumberSequence::updateOrCreate(
            ['period_key' => $format->reset->periodKey($at, $format->yearStartMonth)],
            ['next_value' => max(1, $value)],
        );
    }
}

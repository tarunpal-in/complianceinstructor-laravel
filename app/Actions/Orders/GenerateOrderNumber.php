<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderNumberReset;
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
 * P3 calls this inside the order transaction. Two safeguards make that safe
 * under concurrent checkout: the counter row is locked for the duration, and
 * `orders.number` is UNIQUE, so even if the lock were somehow lost the database
 * refuses the duplicate rather than issuing the same invoice number twice.
 */
class GenerateOrderNumber
{
    public function __invoke(?Carbon $at = null): string
    {
        $at ??= now();
        $format = self::format();

        // A misconfigured format would mint colliding numbers, so it falls back
        // rather than taking the settings on trust. The settings screen refuses
        // to save an invalid one, which makes this the belt to that pair of
        // braces — reachable only by editing the row directly.
        if (! $format->isValid()) {
            $format = new OrderNumberFormat(
                OrderNumberFormat::DEFAULT_PATTERN,
                4,
                OrderNumberReset::Yearly,
            );
        }

        $periodKey = $format->reset->periodKey($at);

        return DB::transaction(function () use ($format, $periodKey, $at): string {
            $sequence = OrderNumberSequence::query()
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = OrderNumberSequence::create([
                    'period_key' => $periodKey,
                    'next_value' => 1,
                ]);
            }

            $value = $sequence->next_value;

            $sequence->update(['next_value' => $value + 1]);

            return $format->render($value, $at);
        });
    }

    /** The configured format, or the default where nothing is set. */
    public static function format(): OrderNumberFormat
    {
        return new OrderNumberFormat(
            (string) Setting::get('orders.number_format', OrderNumberFormat::DEFAULT_PATTERN),
            (int) Setting::get('orders.number_padding', 4),
            OrderNumberReset::tryFrom((string) Setting::get('orders.number_reset', 'yearly'))
                ?? OrderNumberReset::Yearly,
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

        return (int) (OrderNumberSequence::query()
            ->where('period_key', self::format()->reset->periodKey($at))
            ->value('next_value') ?? 1);
    }

    /**
     * Set where the sequence resumes from — used when importing orders that
     * already carry numbers from the old site.
     */
    public static function setNext(int $value, ?Carbon $at = null): void
    {
        $at ??= now();

        OrderNumberSequence::updateOrCreate(
            ['period_key' => self::format()->reset->periodKey($at)],
            ['next_value' => max(1, $value)],
        );
    }
}

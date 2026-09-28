<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orders as a CSV, in the two shapes people actually ask for.
 *
 * **Order-level** is one row per order: what an accountant wants, because it
 * reconciles against a bank statement line for line.
 *
 * **Line-level** is one row per order item, with the webinar, format, speaker
 * and industry attached: what anybody analysing the catalogue wants, and
 * useless for bookkeeping because the totals repeat down the rows.
 *
 * Offering both is the point. People ask for "the orders" and mean one of these
 * two, and handing over the wrong one wastes a day at month end.
 *
 * Money is written as a decimal string throughout and never as a float — §4
 * rule 6 does not stop applying because the number is on its way to Excel.
 */
final class OrderCsv
{
    /**
     * Rows are read in batches.
     *
     * `lazy()` rather than `cursor()`: cursor streams a row at a time and
     * therefore cannot eager load, so every relation below would be a query
     * per row — and under Model::shouldBeStrict, an exception on the first
     * one rather than a slow export.
     */
    private const CHUNK = 200;

    /** @return array<int, string> */
    public static function orderHeaders(): array
    {
        return [
            'Order', 'Placed', 'Paid', 'Customer', 'Email', 'Country',
            'Subtotal', 'Discount', 'Tax', 'Total', 'Captured', 'Refunded',
            'Currency', 'Status', 'Gateways', 'Coupons',
        ];
    }

    /** @return array<int, string> */
    public static function lineHeaders(): array
    {
        return [
            'Order', 'Placed', 'Paid', 'Customer', 'Email',
            'Item', 'SKU', 'Webinar', 'Formats', 'Speaker', 'Industry',
            'Quantity', 'Seats per unit', 'Unit price', 'Line total', 'Currency', 'Status',
        ];
    }

    /**
     * @param  Builder<Order>  $query
     * @return Generator<int, array<int, string|null>>
     */
    public static function orderRows(Builder $query): Generator
    {
        foreach (self::hydrate($query)->lazy(self::CHUNK) as $order) {
            yield [
                $order->number,
                $order->placed_at?->format('Y-m-d H:i'),
                self::paidAt($order),
                $order->customerName(),
                $order->customerEmail(),
                self::country($order),
                Csv::money($order->subtotal_cents),
                Csv::money($order->discount_cents),
                Csv::money($order->tax_cents),
                Csv::money($order->total_cents),
                Csv::money($order->capturedCents()),
                Csv::money($order->refundedCents()),
                $order->currency,
                $order->derivedStatus()->label(),
                self::gateways($order),
                self::coupons($order),
            ];
        }
    }

    /**
     * @param  Builder<Order>  $query
     * @return Generator<int, array<int, string|null>>
     */
    public static function lineRows(Builder $query): Generator
    {
        foreach (self::hydrate($query)->lazy(self::CHUNK) as $order) {
            $paidAt = self::paidAt($order);

            /** @var OrderItem $item */
            foreach ($order->items as $item) {
                yield [
                    $order->number,
                    $order->placed_at?->format('Y-m-d H:i'),
                    $paidAt,
                    $order->customerName(),
                    $order->customerEmail(),
                    $item->description,
                    $item->sku,
                    $item->webinar?->title,
                    $item->formats->pluck('format')->implode(', '),
                    $item->webinar?->speaker?->name,
                    $item->webinar?->industry?->name,
                    (string) $item->quantity,
                    (string) $item->seats_per_unit,
                    Csv::money($item->unit_price_cents),
                    Csv::money($item->line_total_cents),
                    $order->currency,
                    $order->derivedStatus()->label(),
                ];
            }
        }
    }

    /** @param  Builder<Order>  $query */
    public static function downloadOrders(Builder $query): StreamedResponse
    {
        return Csv::download(
            Csv::filename('orders'),
            self::orderHeaders(),
            fn (): iterable => self::orderRows($query),
        );
    }

    /** @param  Builder<Order>  $query */
    public static function downloadLines(Builder $query): StreamedResponse
    {
        return Csv::download(
            Csv::filename('order-lines'),
            self::lineHeaders(),
            fn (): iterable => self::lineRows($query),
        );
    }

    /**
     * Everything both shapes read, loaded up front.
     *
     * capturedCents() and refundedCents() walk the payments and their refunds
     * in memory, so without this every row would be several queries and
     * Model::shouldBeStrict would throw on the first lazy load rather than
     * quietly being slow.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function hydrate(Builder $query): Builder
    {
        return $query->clone()->with([
            'user',
            'payments.refunds',
            'items.formats',
            'items.webinar.speaker',
            'items.webinar.industry',
            'redemptions.coupon',
        ]);
    }

    /**
     * When the money actually arrived.
     *
     * The first succeeded payment, because that is the date the Sales report
     * counts the revenue on — an export whose dates disagree with the report
     * is how two people end up with different figures for one month.
     */
    private static function paidAt(Order $order): ?string
    {
        return $order->payments
            ->where('status', \App\Enums\PaymentStatus::Succeeded)
            ->sortBy('processed_at')
            ->first()?->processed_at?->format('Y-m-d H:i');
    }

    private static function country(Order $order): ?string
    {
        $snapshot = $order->billing_snapshot;

        return is_array($snapshot) && isset($snapshot['country']) && is_string($snapshot['country'])
            ? $snapshot['country']
            : null;
    }

    private static function gateways(Order $order): string
    {
        return $order->payments
            ->where('status', \App\Enums\PaymentStatus::Succeeded)
            ->map(fn ($payment): string => $payment->gateway->label())
            ->unique()
            ->implode(', ');
    }

    private static function coupons(Order $order): string
    {
        return $order->redemptions
            ->map(fn ($redemption): ?string => $redemption->coupon?->code)
            ->filter()
            ->implode(', ');
    }
}

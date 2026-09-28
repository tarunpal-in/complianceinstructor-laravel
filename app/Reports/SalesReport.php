<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the business took over a stretch of time.
 *
 * A plain query class, deliberately outside Filament: the numbers are the part
 * that has to be right, and they are worth testing without booting a panel.
 *
 * **Three decisions are baked in here, and each was a judgement call.** They
 * are stated rather than hidden because a report whose rules are invisible is
 * a report nobody can check.
 *
 * 1. *Money is counted when it is captured, not when the order was placed.*
 *    The date used is `payments.processed_at`, so an order placed on the 30th
 *    and paid on the 2nd belongs to the new month. This is what an accountant
 *    means by revenue, and it is the only date on which the money actually
 *    existed.
 *
 * 2. *A refund reduces the month it happened in, not the month of the original
 *    sale.* Otherwise a refund issued today silently rewrites a month that has
 *    already been reported, and the dashboard starts disagreeing with whatever
 *    was filed. Closed months stay closed.
 *
 * 3. *Where a line covers several formats, its revenue is split evenly between
 *    them.* A package line selling live + recorded contributes half to each.
 *    The alternative — counting it fully against both — makes the format
 *    breakdown add up to more than the revenue, which is worse than being
 *    slightly arbitrary.
 *
 * None of this is pre-aggregated. §8 suggests daily rollup tables; at this
 * catalogue size an indexed SUM is instant, and a rollup cannot cope with
 * decision 2 — a refund today changes a figure the table computed last month.
 * The same reasoning that keeps coupon redemptions and membership allowances
 * counted rather than stored.
 */
class SalesReport
{
    public function __construct(private readonly Period $period) {}

    // Headline ---------------------------------------------------------------------

    /** Everything captured in the period, in cents. */
    public function grossCents(): int
    {
        return (int) $this->capturedQuery()->sum('payments.amount_cents');
    }

    /** Everything given back in the period, in cents. */
    public function refundedCents(): int
    {
        return (int) $this->refundQuery()->sum('refunds.amount_cents');
    }

    public function netCents(): int
    {
        return $this->grossCents() - $this->refundedCents();
    }

    /** Orders that had money captured against them in the period. */
    public function orderCount(): int
    {
        return (int) $this->capturedQuery()->distinct()->count('payments.order_id');
    }

    /**
     * Discount given away on those orders.
     *
     * Read from the orders rather than from coupon redemptions, because an
     * order can be discounted without a coupon and the figure people mean is
     * "what did we not charge".
     */
    public function discountCents(): int
    {
        return (int) $this->ordersPaidInPeriod()->sum('orders.discount_cents');
    }

    public function taxCents(): int
    {
        return (int) $this->ordersPaidInPeriod()->sum('orders.tax_cents');
    }

    /** Net over orders. Zero orders is zero, not a division by zero. */
    public function averageOrderCents(): int
    {
        $orders = $this->orderCount();

        return $orders === 0 ? 0 : intdiv($this->netCents(), $orders);
    }

    /**
     * The whole headline in one pass, for a screen that shows all of it.
     *
     * @return array<string, int>
     */
    public function headline(): array
    {
        return [
            'gross' => $this->grossCents(),
            'refunded' => $this->refundedCents(),
            'net' => $this->netCents(),
            'discount' => $this->discountCents(),
            'tax' => $this->taxCents(),
            'orders' => $this->orderCount(),
            'average' => $this->averageOrderCents(),
        ];
    }

    // Breakdowns ---------------------------------------------------------------------

    /**
     * Revenue over time, at whatever grain the period deserves.
     *
     * Gaps are filled: a day with no sales is a row reading zero, not a missing
     * row. A chart drawn from unfilled data quietly closes the gap and shows a
     * flat line through a week nothing happened.
     *
     * @return Collection<int, ReportRow>
     */
    public function overTime(): Collection
    {
        $grouping = $this->period->grouping();

        $captured = $this->capturedQuery()
            ->selectRaw($this->dateGroupExpression('payments.processed_at', $grouping).' as bucket')
            ->selectRaw('SUM(payments.amount_cents) as cents')
            ->groupBy('bucket')
            ->pluck('cents', 'bucket');

        $refunded = $this->refundQuery()
            ->selectRaw($this->dateGroupExpression('refunds.processed_at', $grouping).' as bucket')
            ->selectRaw('SUM(refunds.amount_cents) as cents')
            ->groupBy('bucket')
            ->pluck('cents', 'bucket');

        return $this->buckets($grouping)
            ->map(fn (array $bucket): ReportRow => new ReportRow(
                label: $bucket['label'],
                cents: (int) ($captured[$bucket['key']] ?? 0) - (int) ($refunded[$bucket['key']] ?? 0),
                key: $bucket['key'],
            ));
    }

    /**
     * Revenue by delivery format.
     *
     * Split evenly where a line sells more than one — see the class comment.
     * Done in PHP rather than SQL because the division is per line and the
     * expression for it differs between MySQL and SQLite, which is a poor
     * trade for a few thousand rows.
     *
     * @return Collection<int, ReportRow>
     */
    public function byFormat(): Collection
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('order_item_format', 'order_item_format.order_item_id', '=', 'order_items.id')
            ->whereIn('orders.id', $this->ordersPaidInPeriod()->select('orders.id'))
            ->whereNull('orders.deleted_at')
            ->select([
                'order_items.id as item_id',
                'order_items.line_total_cents as cents',
                'order_item_format.format as format',
            ])
            ->get();

        $totals = [];

        foreach ($rows->groupBy('item_id') as $formats) {
            $line = (int) $formats->first()->cents;
            $names = $formats->pluck('format')->filter()->values();

            if ($names->isEmpty()) {
                $totals['Not recorded'] = ($totals['Not recorded'] ?? 0) + $line;

                continue;
            }

            // intdiv, so the parts never sum to more than the line. The
            // remainder goes to the first format rather than evaporating.
            $share = intdiv($line, $names->count());
            $remainder = $line - ($share * $names->count());

            foreach ($names as $index => $format) {
                $label = \App\Enums\Format::tryFrom((string) $format)?->label() ?? (string) $format;
                $totals[$label] = ($totals[$label] ?? 0) + $share + ($index === 0 ? $remainder : 0);
            }
        }

        return $this->asRows($totals);
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function byIndustry(): Collection
    {
        return $this->lineTotalsGroupedBy(
            fn (\Illuminate\Database\Query\Builder $query) => $query
                ->leftJoin('webinars', 'webinars.id', '=', 'order_items.webinar_id')
                ->leftJoin('industries', 'industries.id', '=', 'webinars.industry_id')
                ->selectRaw('COALESCE(industries.name, \'Not recorded\') as label'),
        );
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function bySpeaker(): Collection
    {
        return $this->lineTotalsGroupedBy(
            fn (\Illuminate\Database\Query\Builder $query) => $query
                ->leftJoin('webinars', 'webinars.id', '=', 'order_items.webinar_id')
                ->leftJoin('speakers', 'speakers.id', '=', 'webinars.speaker_id')
                ->selectRaw('COALESCE(speakers.name, \'Not recorded\') as label'),
        );
    }

    /**
     * @return Collection<int, ReportRow>
     */
    public function byWebinar(): Collection
    {
        return $this->lineTotalsGroupedBy(
            fn (\Illuminate\Database\Query\Builder $query) => $query
                ->leftJoin('webinars', 'webinars.id', '=', 'order_items.webinar_id')
                // The item's own description, not the webinar's title: an order
                // line is a historical record and the webinar may have been
                // renamed since (§5.4's frozen snapshot, same principle).
                ->selectRaw('COALESCE(webinars.title, order_items.description) as label'),
        );
    }

    /**
     * Revenue by how the money arrived.
     *
     * @return Collection<int, ReportRow>
     */
    public function byGateway(): Collection
    {
        $totals = $this->capturedQuery()
            ->selectRaw('payments.gateway as label')
            ->selectRaw('SUM(payments.amount_cents) as cents')
            ->groupBy('payments.gateway')
            ->pluck('cents', 'label')
            ->mapWithKeys(fn (mixed $cents, string $gateway): array => [
                \App\Enums\PaymentGateway::tryFrom($gateway)?->label() ?? $gateway => (int) $cents,
            ])
            ->all();

        return $this->asRows($totals);
    }

    /**
     * Coupons used on orders paid in the period.
     *
     * @return Collection<int, ReportRow>
     */
    public function byCoupon(): Collection
    {
        return DB::table('coupon_redemptions')
            ->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')
            ->whereIn('coupon_redemptions.order_id', $this->ordersPaidInPeriod()->select('orders.id'))
            ->selectRaw('coupons.code as label')
            ->selectRaw('SUM(coupon_redemptions.amount_cents) as cents')
            ->selectRaw('COUNT(*) as uses')
            ->groupBy('coupons.code')
            ->orderByDesc('cents')
            ->get()
            ->map(fn (object $row): ReportRow => new ReportRow(
                label: (string) $row->label,
                cents: (int) $row->cents,
                note: trans_choice(':count use|:count uses', (int) $row->uses, ['count' => (int) $row->uses]),
            ));
    }

    // Queries -------------------------------------------------------------------------

    /**
     * Payments captured inside the period.
     *
     * `processed_at`, never `created_at`: a payment row is written when the
     * attempt starts and stamped when the gateway confirms, and the second is
     * when the money existed.
     *
     * @return Builder<Payment>
     */
    private function capturedQuery(): Builder
    {
        return Payment::query()
            ->where('payments.status', PaymentStatus::Succeeded)
            ->whereBetween('payments.processed_at', [$this->period->from, $this->period->to]);
    }

    /** @return Builder<Refund> */
    private function refundQuery(): Builder
    {
        return Refund::query()
            ->where('refunds.status', RefundStatus::Succeeded)
            ->whereBetween('refunds.processed_at', [$this->period->from, $this->period->to]);
    }

    /**
     * Orders with money captured against them in the period.
     *
     * Distinct, because an order paid in two instalments would otherwise have
     * its discount and tax counted twice.
     *
     * @return Builder<Order>
     */
    private function ordersPaidInPeriod(): Builder
    {
        return Order::query()
            ->whereIn('orders.id', $this->capturedQuery()->select('payments.order_id'));
    }

    /**
     * Line totals grouped by whatever the caller joins on.
     *
     * @param  callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder  $shape
     * @return Collection<int, ReportRow>
     */
    private function lineTotalsGroupedBy(callable $shape): Collection
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $this->ordersPaidInPeriod()->select('orders.id'))
            ->whereNull('orders.deleted_at');

        return $shape($query)
            ->selectRaw('SUM(order_items.line_total_cents) as cents')
            ->groupBy('label')
            ->orderByDesc('cents')
            ->get()
            ->map(fn (object $row): ReportRow => new ReportRow(
                label: (string) $row->label,
                cents: (int) $row->cents,
            ));
    }

    /**
     * A date expression that groups the same way on MySQL and SQLite.
     *
     * The tests run on SQLite and production is MariaDB; a report that is only
     * correct on one of them is a report nobody can test.
     */
    private function dateGroupExpression(string $column, string $grouping): string
    {
        $driver = DB::connection()->getDriverName();

        $format = match ($grouping) {
            'month' => ['mysql' => '%Y-%m', 'sqlite' => '%Y-%m'],
            'week' => ['mysql' => '%x-W%v', 'sqlite' => '%Y-W%W'],
            default => ['mysql' => '%Y-%m-%d', 'sqlite' => '%Y-%m-%d'],
        };

        return $driver === 'sqlite'
            ? "strftime('{$format['sqlite']}', {$column})"
            : "DATE_FORMAT({$column}, '{$format['mysql']}')";
    }

    /**
     * Every bucket in the period, including the empty ones.
     *
     * @return Collection<int, array{key: string, label: string}>
     */
    private function buckets(string $grouping): Collection
    {
        $buckets = collect();
        $cursor = match ($grouping) {
            'month' => $this->period->from->startOfMonth(),
            'week' => $this->period->from->startOfWeek(),
            default => $this->period->from->startOfDay(),
        };

        while ($cursor->lessThanOrEqualTo($this->period->to)) {
            $buckets->push(match ($grouping) {
                'month' => ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')],
                'week' => ['key' => $cursor->format('Y-\WW'), 'label' => 'w/c '.$cursor->format('j M')],
                default => ['key' => $cursor->format('Y-m-d'), 'label' => $cursor->format('j M')],
            });

            $cursor = match ($grouping) {
                'month' => $cursor->addMonthNoOverflow(),
                'week' => $cursor->addWeek(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    /**
     * @param  array<string, int>  $totals
     * @return Collection<int, ReportRow>
     */
    private function asRows(array $totals): Collection
    {
        arsort($totals);

        return collect($totals)
            ->map(fn (int $cents, string $label): ReportRow => new ReportRow($label, $cents))
            ->values();
    }
}

<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Support\Period;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the discounting is costing — §8's "coupon performance and discount cost".
 *
 * **Redemptions are counted by when they were redeemed**, and the revenue shown
 * against a code is what has been captured on those orders, whenever it was
 * captured. Those are two different dates and it is worth saying so: an invoice
 * discounted in March and paid in April appears in March's redemptions with
 * April's money beside it. The alternative — restricting the revenue to the
 * same window — would show a code as pure cost in the month it was used.
 *
 * Nothing here claims a coupon *caused* a sale. See CouponRow.
 */
class CouponPerformance
{
    /** How soon counts as "expiring", for the warning list. */
    private const EXPIRING_WITHIN_DAYS = 30;

    public function __construct(private readonly Period $period) {}

    /**
     * Every code used in the period, dearest first.
     *
     * @return Collection<int, CouponRow>
     */
    public function rows(): Collection
    {
        $redemptions = DB::table('coupon_redemptions')
            ->whereBetween('redeemed_at', [$this->period->from, $this->period->to])
            ->groupBy('coupon_id')
            ->selectRaw('coupon_id')
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('SUM(amount_cents) as discount')
            ->get()
            ->keyBy('coupon_id');

        if ($redemptions->isEmpty()) {
            return collect();
        }

        $revenue = $this->revenueByCoupon($redemptions->keys()->all());

        return Coupon::query()
            ->whereIn('id', $redemptions->keys()->all())
            ->get()
            ->map(fn (Coupon $coupon): CouponRow => new CouponRow(
                coupon: $coupon,
                uses: (int) $redemptions[$coupon->getKey()]->uses,
                discountCents: (int) $redemptions[$coupon->getKey()]->discount,
                revenueCents: (int) ($revenue[$coupon->getKey()] ?? 0),
            ))
            ->sortByDesc(fn (CouponRow $row): int => $row->discountCents)
            ->values();
    }

    /** What the discounting cost in total, in cents. */
    public function totalDiscountCents(): int
    {
        return (int) $this->rows()->sum(fn (CouponRow $row): int => $row->discountCents);
    }

    /** What the orders carrying a code were worth, in cents. */
    public function totalRevenueCents(): int
    {
        return (int) $this->rows()->sum(fn (CouponRow $row): int => $row->revenueCents);
    }

    public function totalUses(): int
    {
        return (int) $this->rows()->sum(fn (CouponRow $row): int => $row->uses);
    }

    /**
     * Codes that are live and have never been used.
     *
     * Not a failure on its own — a code printed on a conference flyer is meant
     * to sit unused until somebody types it. It is worth seeing because an
     * unused code is either not reaching anybody or not worth reaching them
     * with, and neither is visible anywhere else.
     *
     * @return Collection<int, Coupon>
     */
    public function neverUsed(): Collection
    {
        return Coupon::query()
            ->where('is_active', true)
            ->whereDoesntHave('redemptions')
            ->orderBy('code')
            ->get();
    }

    /**
     * Live codes about to run out of time or redemptions.
     *
     * The operational half of this report: a code expiring next week that is
     * still being handed out is a support conversation nobody wants.
     *
     * @return Collection<int, Coupon>
     */
    public function endingSoon(): Collection
    {
        return Coupon::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [Period::now(), Period::now()->addDays(self::EXPIRING_WITHIN_DAYS)])
            ->orderBy('ends_at')
            ->get();
    }

    /**
     * Money captured on the orders each of these coupons was used on.
     *
     * The same definition of revenue the sales report uses — succeeded payments
     * — so the two screens cannot disagree about what an order was worth.
     *
     * @param  array<int, mixed>  $couponIds
     * @return array<int, int>
     */
    private function revenueByCoupon(array $couponIds): array
    {
        return DB::table('coupon_redemptions')
            ->join('payments', 'payments.order_id', '=', 'coupon_redemptions.order_id')
            ->whereIn('coupon_redemptions.coupon_id', $couponIds)
            ->whereBetween('coupon_redemptions.redeemed_at', [$this->period->from, $this->period->to])
            ->where('payments.status', PaymentStatus::Succeeded->value)
            ->groupBy('coupon_redemptions.coupon_id')
            ->selectRaw('coupon_redemptions.coupon_id as coupon_id')
            ->selectRaw('SUM(payments.amount_cents) as cents')
            ->pluck('cents', 'coupon_id')
            ->map(fn (mixed $cents): int => (int) $cents)
            ->all();
    }
}

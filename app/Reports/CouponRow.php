<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Coupon;

/**
 * One coupon's figures for a period.
 *
 * `revenueCents` is **revenue on the orders this code was used on** — not
 * revenue the coupon produced. Nothing here can tell you whether those people
 * would have bought anyway, and a column headed "revenue generated" would be
 * claiming it did. The honest reading is: this is what those orders were worth,
 * and `discountCents` is what it cost to get them.
 */
final class CouponRow
{
    public function __construct(
        public readonly Coupon $coupon,
        public readonly int $uses,
        public readonly int $discountCents,
        public readonly int $revenueCents,
    ) {}

    /**
     * Discount as a share of what those orders were worth.
     *
     * The figure worth arguing over: a code giving away 40% of the revenue it
     * appears on is either buying growth or subsidising people who were going
     * to buy regardless, and only the client knows which.
     */
    public function discountRate(): ?float
    {
        if ($this->revenueCents + $this->discountCents <= 0) {
            return null;
        }

        return round(($this->discountCents / ($this->revenueCents + $this->discountCents)) * 100, 1);
    }

    /** What an order using this code was worth, on average. */
    public function averageOrderCents(): int
    {
        return $this->uses === 0 ? 0 : intdiv($this->revenueCents, $this->uses);
    }

    /**
     * How much of the redemption cap has gone, as a percentage.
     *
     * Null where there is no cap — nothing to run out of, and a progress bar
     * against infinity is meaningless.
     */
    public function capUsed(): ?float
    {
        $limit = $this->coupon->max_redemptions;

        if ($limit === null || $limit <= 0) {
            return null;
        }

        return min(100.0, round(($this->coupon->redemptionCount() / $limit) * 100, 1));
    }
}

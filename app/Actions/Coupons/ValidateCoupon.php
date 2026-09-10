<?php

declare(strict_types=1);

namespace App\Actions\Coupons;

use App\Coupons\CouponVerdict;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Money;

/**
 * Decides whether a coupon applies to an order, and for how much.
 *
 * §14 #3 — "never validate a coupon client-side, and never serialise the coupon
 * table into a page". The predecessor did both: `Coupon::all()` was embedded in
 * the checkout HTML, so every code, value and expiry was readable in the page
 * source, and the only check was JavaScript. This is the whole of the check,
 * and it runs on the server.
 *
 * §8 improvement #2 has the redemption written **inside the order transaction**.
 * This action only decides; RedeemCoupon writes, under the lock, and re-runs
 * this immediately before doing so — because between an admin previewing a code
 * and a customer using it, the last redemption may have gone.
 */
class ValidateCoupon
{
    public function __invoke(Coupon $coupon, Order $order, ?User $user = null): CouponVerdict
    {
        if (! $coupon->is_active) {
            return CouponVerdict::refused('That code is not available.');
        }

        if (! $coupon->hasStarted()) {
            return CouponVerdict::refused('That code cannot be used yet.');
        }

        if ($coupon->hasExpired()) {
            return CouponVerdict::refused('That code has expired.');
        }

        if ($coupon->isExhausted()) {
            return CouponVerdict::refused('That code has been fully redeemed.');
        }

        // Only constrains signed-in customers. A guest checkout has no identity
        // to count against, which is the honest limit of what this cap can
        // promise — worth knowing rather than assuming it covers everyone.
        if ($user !== null
            && $coupon->max_per_user !== null
            && $coupon->redemptionCountFor($user) >= $coupon->max_per_user
        ) {
            return CouponVerdict::refused('You have already used that code.');
        }

        // Already on this order. The UNIQUE would catch it at the database, but
        // a retried checkout should read as "already applied", not as a crash.
        if ($coupon->redemptions()->where('order_id', $order->getKey())->exists()) {
            return CouponVerdict::refused('That code is already applied to this order.');
        }

        $eligible = $this->eligibleCents($coupon, $order);

        if ($eligible < 1) {
            return CouponVerdict::refused('That code does not apply to anything in this order.');
        }

        // Measured against the eligible lines, not the order total: a coupon
        // scoped to one industry should not be unlocked by spending on another.
        if ($coupon->minimum_spend_cents !== null && $eligible < $coupon->minimum_spend_cents) {
            return CouponVerdict::refused(sprintf(
                'That code needs a spend of at least %s on qualifying items.',
                Money::format($coupon->minimum_spend_cents, $coupon->currency),
            ));
        }

        $discount = $coupon->discountFor($eligible);

        if ($discount < 1) {
            return CouponVerdict::refused('That code would take nothing off this order.');
        }

        return CouponVerdict::accepted($discount);
    }

    /**
     * The part of the order the coupon applies to.
     *
     * A scoped coupon discounts only its own lines. "20% off HR webinars"
     * against a basket holding one HR webinar and one finance webinar takes 20%
     * of the first, not of the basket — anything else quietly gives away more
     * than the offer said.
     */
    private function eligibleCents(Coupon $coupon, Order $order): int
    {
        $order->loadMissing(['items.webinar', 'items.formats']);

        return (int) $order->items
            ->filter(fn (OrderItem $item): bool => $this->covers($coupon, $item))
            ->sum('line_total_cents');
    }

    private function covers(Coupon $coupon, OrderItem $item): bool
    {
        if ($coupon->scopeable_id !== null && ! $this->matchesRecord($coupon, $item)) {
            return false;
        }

        // Enum comparison, never a string one (§14 #12).
        if ($coupon->scope_format !== null
            && ! $item->formatEnums()->contains($coupon->scope_format)
        ) {
            return false;
        }

        return true;
    }

    private function matchesRecord(Coupon $coupon, OrderItem $item): bool
    {
        return match ($coupon->scopeable_type) {
            \App\Models\Webinar::class => $item->webinar_id === $coupon->scopeable_id,
            \App\Models\Industry::class => $item->webinar?->industry_id === $coupon->scopeable_id,
            default => false,
        };
    }
}

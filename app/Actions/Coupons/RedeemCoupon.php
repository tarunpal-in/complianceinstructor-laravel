<?php

declare(strict_types=1);

namespace App\Actions\Coupons;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Takes a redemption, under the lock that makes the cap mean something.
 *
 * §8 improvement #2 — "checked **inside the order transaction** so concurrent
 * checkouts cannot both take the last redemption". Two things make that true
 * rather than hoped for:
 *
 *  - the coupon row is locked, so the count is read and the row written without
 *    anyone else reading the same count in between;
 *  - `UNIQUE (coupon_id, order_id)` refuses a second row for the same order
 *    even if the lock were somehow lost.
 *
 * P3's PlaceOrder calls this inside the transaction that creates the order, so
 * a failed capture rolls the redemption back with everything else — a coupon
 * must not be spent by a checkout that never completed.
 */
class RedeemCoupon
{
    public function __construct(private readonly ValidateCoupon $validate) {}

    public function __invoke(
        Coupon $coupon,
        Order $order,
        ?User $user = null,
    ): CouponRedemption {
        return DB::transaction(function () use ($coupon, $order, $user): CouponRedemption {
            $locked = Coupon::query()
                ->whereKey($coupon->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-validated under the lock, not merely trusted from whenever the
            // customer was shown a price. Between the cart saying "£20 off" and
            // the order being placed, the last redemption may have gone.
            $verdict = ($this->validate)($locked, $order, $user);

            if (! $verdict->accepted) {
                throw new RuntimeException((string) $verdict->reason);
            }

            return CouponRedemption::create([
                'coupon_id' => $locked->getKey(),
                'user_id' => $user?->getKey(),
                'order_id' => $order->getKey(),
                // Snapshotted: the coupon can be edited afterwards, and this has
                // to keep matching the invoice it appears on.
                'amount_cents' => $verdict->discountCents,
                'redeemed_at' => now(),
            ]);
        });
    }
}

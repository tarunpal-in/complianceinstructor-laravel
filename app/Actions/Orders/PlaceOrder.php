<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Coupons\RedeemCoupon;
use App\Checkout\BillingDetails;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a cart into a pending order — CLAUDE.md §4 rule 2 / §5.4 (P3).
 *
 * Everything happens in ONE transaction (§14 #4): the order header with its
 * number, a line per cart item snapshotting the price and formats sold, a named
 * seat per unit, and the coupon redeemed inside the same transaction so a later
 * failure rolls back the discount, the redemption row and the order number
 * together.
 *
 * Nothing is trusted from the request. Every price is re-read from the offering
 * (§14 #1), the total is computed here, and the order's money never comes from
 * the cart's snapshot or a client field. Payment is NOT taken here — this
 * produces a Pending order whose status only becomes Paid when a verified
 * payment row says so (§14 #2); capture is the next step.
 */
class PlaceOrder
{
    public function __construct(private readonly RedeemCoupon $redeemCoupon) {}

    /**
     * @param  array<int, array{name?: string|null, email?: string|null}>  $attendees  one per seat, in order; missing seats fall back to the buyer
     *
     * @throws RuntimeException when the cart is empty or a coupon is refused
     */
    public function __invoke(
        Cart $cart,
        BillingDetails $billing,
        ?User $user = null,
        ?string $couponCode = null,
        array $attendees = [],
    ): Order {
        $cart->loadMissing('items.offering.formats', 'items.offering.offerable');
        $currency = $cart->currency;

        /** @var \Illuminate\Support\Collection<int, CartItem> $items */
        $items = $cart->items->filter(fn (CartItem $item): bool => $item->isAvailable())->values();

        if ($items->isEmpty()) {
            throw new RuntimeException('Your cart is empty.');
        }

        return DB::transaction(function () use ($items, $billing, $user, $couponCode, $attendees, $currency): Order {
            $order = Order::create([
                // One number, taken once inside the transaction — a rollback
                // (e.g. a refused coupon below) returns it to the pool.
                'number' => Order::nextNumber(),
                'user_id' => $user?->getKey(),
                'status' => OrderStatus::Pending,
                'currency' => $currency,
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'total_cents' => 0,
                'billing_snapshot' => $billing->toSnapshot(),
                'placed_at' => now(),
            ]);

            $subtotal = $this->buildLines($order, $items, $billing, $user, $attendees);
            $discount = $this->applyCoupon($order, $couponCode, $user);

            // No tax rows yet — tax handling is a P0-workshop decision and the
            // seeded data treats it as zero; this is the seam for `tax_rates`.
            $tax = 0;
            $total = max(0, $subtotal - $discount + $tax);

            $order->update([
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discount,
                'tax_cents' => $tax,
                'total_cents' => $total,
            ]);

            return $order->fresh(['items.formats', 'items.attendees']) ?? $order;
        });
    }

    /**
     * Create the order lines, their format snapshots and their seats. Returns
     * the subtotal, summed from prices re-read from each offering.
     *
     * @param  \Illuminate\Support\Collection<int, CartItem>  $items
     * @param  array<int, array{name?: string|null, email?: string|null}>  $attendees
     */
    private function buildLines(Order $order, $items, BillingDetails $billing, ?User $user, array $attendees): int
    {
        $subtotal = 0;
        $seatCursor = 0;

        foreach ($items as $item) {
            $offering = $item->offering;
            // The price source (§5.3/§14 #1) — never the cart snapshot.
            $price = $offering->priceCents();
            $quantity = $item->quantity;
            $webinar = $offering->offerable instanceof Webinar ? $offering->offerable : null;

            $line = $order->items()->create([
                'offering_id' => $offering->getKey(),
                'webinar_id' => $webinar?->getKey(),
                'description' => $offering->label(),
                'sku' => $offering->sku,
                'quantity' => $quantity,
                'unit_price_cents' => $price,
                'line_total_cents' => $price * $quantity,
                'seats_per_unit' => $offering->seats_per_unit,
            ]);

            // Formats are snapshotted onto the line, not read back off the
            // offering later (the offering could change).
            foreach ($offering->formatEnums() as $format) {
                $line->formats()->create(['format' => $format]);
            }

            for ($seat = 0; $seat < $line->seatCount(); $seat++) {
                $spec = $attendees[$seatCursor] ?? null;
                $name = is_array($spec) && filled($spec['name'] ?? null) ? (string) $spec['name'] : $billing->name;
                $email = is_array($spec) && filled($spec['email'] ?? null) ? (string) $spec['email'] : $billing->email;

                $line->attendees()->create([
                    'name' => $name,
                    'email' => $email,
                    // A logged-in buyer claims the seat that matches their own
                    // email; every other seat is unclaimed until registered.
                    'user_id' => $user !== null && strcasecmp($email, (string) $user->email) === 0
                        ? $user->getKey()
                        : null,
                ]);

                $seatCursor++;
            }

            $subtotal += $price * $quantity;
        }

        return $subtotal;
    }

    /**
     * Redeem a coupon inside the order transaction, returning the discount.
     *
     * RedeemCoupon re-validates under a lock and writes the `coupon_redemptions`
     * row whose UNIQUE(coupon,order) stops a double-spend; a refusal throws and
     * rolls the whole order back (§8 / §14 #3).
     */
    private function applyCoupon(Order $order, ?string $couponCode, ?User $user): int
    {
        if (! filled($couponCode)) {
            return 0;
        }

        $coupon = Coupon::query()->matchingCode($couponCode)->first();

        if ($coupon === null) {
            throw new RuntimeException('That coupon code was not recognised.');
        }

        // ValidateCoupon (inside RedeemCoupon) scopes against the order's lines
        // and their formats.
        $order->load('items.formats');

        return ($this->redeemCoupon)($coupon, $order, $user)->amount_cents;
    }
}

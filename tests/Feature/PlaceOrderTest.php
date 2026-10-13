<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrder;
use App\Checkout\BillingDetails;
use App\Enums\Format;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Offering;
use App\Models\Order;
use App\Models\User;

/*
 * PlaceOrder turns a cart into a pending order in one transaction. This is the
 * money-correct core of P3 (§3 hard gate / §10 exhaustive): every total is
 * recomputed from the offering, formats and seats are snapshotted, and a refused
 * coupon rolls the whole thing back.
 */

function billing(string $name = 'Dana Buyer', string $email = 'dana@example.test'): BillingDetails
{
    return new BillingDetails($name, $email);
}

/** A cart holding one offering at a quantity. */
function cartWith(Offering $offering, int $quantity = 1): Cart
{
    $cart = Cart::factory()->create(['currency' => 'USD']);
    CartItem::factory()->for($cart)->create([
        'offering_id' => $offering->id,
        'quantity' => $quantity,
        'unit_price_cents' => 1,  // deliberately wrong — proves the snapshot is ignored
    ]);

    return $cart->load('items.offering.formats', 'items.offering.offerable');
}

it('builds a pending order, pricing every line from the offering not the cart snapshot', function (): void {
    $offering = Offering::factory()->live()->create(['price_cents' => 17900]);
    $cart = cartWith($offering, 2);

    $order = app(PlaceOrder::class)($cart, billing());

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->items)->toHaveCount(1);

    $line = $order->items->first();
    expect($line->unit_price_cents)->toBe(17900)        // from the offering, not the cart's 1
        ->and($line->line_total_cents)->toBe(35800)
        ->and($line->totalIsConsistent())->toBeTrue()
        ->and($order->subtotal_cents)->toBe(35800)
        ->and($order->total_cents)->toBe(35800)
        ->and($order->discount_cents)->toBe(0);
});

it('freezes a unique order number and the billing snapshot', function (): void {
    $cart = cartWith(Offering::factory()->live()->create());

    $order = app(PlaceOrder::class)($cart, billing('Priya', 'priya@example.test'));

    expect($order->number)->not->toBeEmpty()
        ->and($order->billing_snapshot['name'])->toBe('Priya')
        ->and($order->billing_snapshot['email'])->toBe('priya@example.test')
        ->and($order->placed_at)->not->toBeNull();
});

it('snapshots every format sold onto the line', function (): void {
    // A three-format bundle.
    $offering = Offering::factory()->everything()->create();
    $order = app(PlaceOrder::class)(cartWith($offering), billing());

    $formats = $order->items->first()->formatEnums();

    expect($formats->all())->toEqualCanonicalizing([Format::Live, Format::Recorded, Format::Transcript]);
});

it('creates one named seat per unit bought', function (): void {
    $offering = Offering::factory()->live()->create(['seats_per_unit' => 3]);
    $order = app(PlaceOrder::class)(cartWith($offering, 2), billing());

    // 2 units × 3 seats each.
    expect($order->items->first()->attendees)->toHaveCount(6);
});

it('claims the buyer\'s own seat but leaves the rest unclaimed', function (): void {
    $user = User::factory()->create(['email' => 'dana@example.test']);
    $offering = Offering::factory()->live()->create();

    $order = app(PlaceOrder::class)(cartWith($offering, 2), billing('Dana', 'dana@example.test'), $user, null, [
        ['name' => 'Dana', 'email' => 'dana@example.test'],
        ['name' => 'Colleague', 'email' => 'colleague@example.test'],
    ]);

    $attendees = $order->items->first()->attendees;

    expect($order->user_id)->toBe($user->id)
        ->and($attendees->firstWhere('email', 'dana@example.test')->user_id)->toBe($user->id)
        ->and($attendees->firstWhere('email', 'colleague@example.test')->user_id)->toBeNull();
});

it('places a guest order with no user and a captured email', function (): void {
    $order = app(PlaceOrder::class)(cartWith(Offering::factory()->live()->create()), billing('Guest', 'guest@example.test'));

    expect($order->user_id)->toBeNull()
        ->and($order->customerEmail())->toBe('guest@example.test')
        ->and($order->items->first()->attendees->first()->user_id)->toBeNull();
});

it('applies a coupon inside the order, discounting the total', function (): void {
    $offering = Offering::factory()->live()->create(['price_cents' => 20000]);
    $coupon = Coupon::factory()->create([
        'code' => 'TENOFF',
        'percent_off' => null,
        'amount_off_cents' => 5000,
        'currency' => 'USD',
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'max_redemptions' => null,
        'max_per_user' => null,
        'minimum_spend_cents' => null,
        'scopeable_type' => null,
        'scopeable_id' => null,
        'scope_format' => null,
    ]);

    $order = app(PlaceOrder::class)(cartWith($offering), billing(), null, 'TENOFF');

    expect($order->discount_cents)->toBe(5000)
        ->and($order->total_cents)->toBe(15000)
        ->and($order->redemptions)->toHaveCount(1);
});

it('rolls the whole order back when a coupon is refused', function (): void {
    $expired = Coupon::factory()->create([
        'code' => 'EXPIRED',
        'amount_off_cents' => 1000,
        'percent_off' => null,
        'is_active' => true,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subDay(),   // over
        'scopeable_type' => null,
        'scopeable_id' => null,
        'scope_format' => null,
    ]);

    $place = fn () => app(PlaceOrder::class)(cartWith(Offering::factory()->live()->create()), billing(), null, 'EXPIRED');

    expect($place)->toThrow(RuntimeException::class);
    // No order, no line, no consumed number — the transaction rolled back.
    expect(Order::query()->count())->toBe(0);
});

it('refuses an unknown coupon code', function (): void {
    $place = fn () => app(PlaceOrder::class)(cartWith(Offering::factory()->live()->create()), billing(), null, 'NOPE');

    expect($place)->toThrow(RuntimeException::class);
    expect(Order::query()->count())->toBe(0);
});

it('refuses to place an empty cart', function (): void {
    $cart = Cart::factory()->create();

    expect(fn () => app(PlaceOrder::class)($cart, billing()))->toThrow(RuntimeException::class);
});

it('sums several lines into the subtotal', function (): void {
    $cart = Cart::factory()->create(['currency' => 'USD']);
    $a = Offering::factory()->live()->create(['price_cents' => 10000]);
    $b = Offering::factory()->recorded()->create(['price_cents' => 5000]);
    CartItem::factory()->for($cart)->create(['offering_id' => $a->id, 'quantity' => 1]);
    CartItem::factory()->for($cart)->create(['offering_id' => $b->id, 'quantity' => 2]);

    $order = app(PlaceOrder::class)($cart->load('items.offering.formats', 'items.offering.offerable'), billing());

    expect($order->items)->toHaveCount(2)
        ->and($order->subtotal_cents)->toBe(10000 + 10000);
});

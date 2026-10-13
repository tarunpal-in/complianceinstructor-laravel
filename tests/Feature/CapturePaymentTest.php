<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\RecordCapture;
use App\Checkout\BillingDetails;
use App\Enums\Format;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Entitlement;
use App\Models\Offering;
use App\Models\Order;
use App\Models\User;
use App\Payments\CaptureResult;

/*
 * The money-path hard gate — CLAUDE.md §3. "Do not leave P3 until a Pest suite
 * proves: a successful capture grants exactly the right entitlements; a
 * duplicate webhook does not double-grant; a failed capture grants nothing."
 * (A refund revokes — slice 5.)
 */

/** A pending order for a buyer, all seats theirs so they are claimed. */
function pendingOrder(User $buyer, Offering $offering, int $quantity = 1): Order
{
    $cart = Cart::factory()->create(['currency' => 'USD']);
    CartItem::factory()->for($cart)->create(['offering_id' => $offering->id, 'quantity' => $quantity]);

    $seats = $quantity * (int) $offering->seats_per_unit;

    return app(PlaceOrder::class)(
        $cart->load('items.offering.formats', 'items.offering.offerable'),
        new BillingDetails($buyer->name, (string) $buyer->email),
        $buyer,
        null,
        array_fill(0, $seats, ['name' => $buyer->name, 'email' => (string) $buyer->email]),
    );
}

it('grants exactly the entitlements the order bought on a successful capture', function (): void {
    $buyer = User::factory()->create();
    $offering = Offering::factory()->live()->create(['price_cents' => 20000]);
    $order = pendingOrder($buyer, $offering);
    $webinar = $offering->offerable;

    $payment = app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-123', 20000));

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->fresh()->derivedStatus())->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(Entitlement::grants($buyer, $webinar, Format::Live))->toBeTrue();
});

it('grants one entitlement per format on a bundle line', function (): void {
    $buyer = User::factory()->create();
    $offering = Offering::factory()->everything()->create(); // Live + Recorded + Transcript
    $order = pendingOrder($buyer, $offering);
    $webinar = $offering->offerable;

    app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-456', $order->total_cents));

    expect(Entitlement::query()->count())->toBe(3)
        ->and(Entitlement::grants($buyer, $webinar, Format::Live))->toBeTrue()
        ->and(Entitlement::grants($buyer, $webinar, Format::Recorded))->toBeTrue()
        ->and(Entitlement::grants($buyer, $webinar, Format::Transcript))->toBeTrue();
});

it('does not double-grant when the same capture is recorded twice', function (): void {
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, Offering::factory()->live()->create());

    $first = app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-DUP', $order->total_cents));
    // The webhook replays the same capture id — the idempotency guard (§14 #5).
    $second = app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-DUP', $order->total_cents));

    expect($second->id)->toBe($first->id)
        ->and($order->payments()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(1);
});

it('grants nothing and does not mark paid on a failed capture', function (): void {
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, Offering::factory()->live()->create());

    $payment = app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::failed('Card declined.'));

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($order->fresh()->derivedStatus())->not->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(0);
});

it('takes the money but grants no access for an unclaimed guest seat', function (): void {
    // A guest order: the attendee has no account, so there is no one to grant to
    // yet — access waits for the seat to be claimed.
    $cart = Cart::factory()->create(['currency' => 'USD']);
    $offering = Offering::factory()->live()->create(['price_cents' => 15000]);
    CartItem::factory()->for($cart)->create(['offering_id' => $offering->id, 'quantity' => 1]);

    $order = app(PlaceOrder::class)(
        $cart->load('items.offering.formats', 'items.offering.offerable'),
        new BillingDetails('Guest', 'guest@example.test'),
        null,
        null,
        [['name' => 'Guest', 'email' => 'guest@example.test']],
    );

    $payment = app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-GUEST', 15000));

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->fresh()->derivedStatus())->toBe(OrderStatus::Paid)   // money moved
        ->and(Entitlement::query()->count())->toBe(0);                     // no access granted yet
});

it('links the seat to its granted entitlement', function (): void {
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, Offering::factory()->live()->create());

    app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-LINK', $order->total_cents));

    $attendee = $order->items->first()->attendees->first();
    expect($attendee->fresh()->entitlement_id)->not->toBeNull();
});

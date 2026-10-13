<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\ProcessStripeCaptureEvent;
use App\Checkout\BillingDetails;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Entitlement;
use App\Models\Offering;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * The Stripe capture webhook (§14 #6). Finalising reuses Cashier's verified
 * endpoint via the HandleStripeWebhook listener, so this drives the processing
 * directly and checks the listener is wired.
 */

function stripeOrder(User $buyer): Order
{
    $offering = Offering::factory()->live()->create(['price_cents' => 20000]);
    $cart = Cart::factory()->forUser($buyer)->create(['currency' => 'USD']);
    CartItem::factory()->for($cart)->create(['offering_id' => $offering->id, 'quantity' => 1]);

    return app(PlaceOrder::class)(
        $cart->load('items.offering.formats', 'items.offering.offerable'),
        new BillingDetails((string) $buyer->name, (string) $buyer->email),
        $buyer,
        null,
        [['name' => (string) $buyer->name, 'email' => (string) $buyer->email]],
    );
}

/** @return array<string, mixed> */
function stripeIntentEvent(Order $order, string $intentId = 'pi_test_1'): array
{
    return [
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => $intentId,
            'amount_received' => $order->total_cents,
            'metadata' => ['order_id' => (string) $order->getKey()],
        ]],
    ];
}

it('finalises an order from a payment_intent.succeeded event', function (): void {
    $buyer = User::factory()->create();
    $order = stripeOrder($buyer);

    app(ProcessStripeCaptureEvent::class)(stripeIntentEvent($order));

    expect($order->fresh()->derivedStatus())->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(1);
});

it('does not grant twice when Stripe replays the event', function (): void {
    $buyer = User::factory()->create();
    $order = stripeOrder($buyer);

    app(ProcessStripeCaptureEvent::class)(stripeIntentEvent($order));
    app(ProcessStripeCaptureEvent::class)(stripeIntentEvent($order)); // same PaymentIntent id

    expect(Payment::query()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(1);
});

it('ignores a non-capture Stripe event', function (): void {
    $order = stripeOrder(User::factory()->create());

    $result = app(ProcessStripeCaptureEvent::class)([
        'type' => 'customer.updated',
        'data' => ['object' => ['id' => 'cus_1', 'metadata' => ['order_id' => (string) $order->getKey()]]],
    ]);

    expect($result)->toBeNull()
        ->and(Payment::query()->count())->toBe(0);
});

it('finalises through the Cashier webhook listener', function (): void {
    $buyer = User::factory()->create();
    $order = stripeOrder($buyer);

    // Cashier verifies the signature then fires this; our listener acts on it.
    event(new WebhookReceived(stripeIntentEvent($order)));

    expect($order->fresh()->derivedStatus())->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(1);
});

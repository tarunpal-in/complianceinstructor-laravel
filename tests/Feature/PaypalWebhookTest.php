<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\ProcessPaypalCaptureEvent;
use App\Checkout\BillingDetails;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Entitlement;
use App\Models\Offering;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

/*
 * The PayPal capture webhook (§14 #6 — never rely on the browser returning).
 * Verification needs real PayPal, so the processing is tested directly; the
 * endpoint is tested to refuse what it cannot verify.
 */

function webhookOrder(User $buyer): Order
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
function captureEvent(Order $order, string $captureId = 'WH-CAP-1'): array
{
    return [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => $captureId,
            'custom_id' => (string) $order->getKey(),
            'amount' => ['value' => '200.00', 'currency_code' => 'USD'],
        ],
    ];
}

it('finalises an order from a capture-completed event', function (): void {
    $buyer = User::factory()->create();
    $order = webhookOrder($buyer);

    app(ProcessPaypalCaptureEvent::class)(captureEvent($order));

    expect($order->fresh()->derivedStatus())->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(1);
});

it('does not grant twice when PayPal replays the event', function (): void {
    $buyer = User::factory()->create();
    $order = webhookOrder($buyer);

    app(ProcessPaypalCaptureEvent::class)(captureEvent($order));
    app(ProcessPaypalCaptureEvent::class)(captureEvent($order)); // same capture id

    expect(Payment::query()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(1);
});

it('ignores a non-capture event', function (): void {
    $order = webhookOrder(User::factory()->create());

    $result = app(ProcessPaypalCaptureEvent::class)([
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => 'x', 'custom_id' => (string) $order->getKey()],
    ]);

    expect($result)->toBeNull()
        ->and(Payment::query()->count())->toBe(0);
});

it('refuses a webhook it cannot verify', function (): void {
    // No PAYPAL_WEBHOOK_ID in the test environment, so the signature cannot be
    // verified and the event is rejected rather than trusted.
    $this->postJson(route('webhooks.paypal'), captureEvent(webhookOrder(User::factory()->create())))
        ->assertStatus(403);

    expect(Payment::query()->count())->toBe(0);
});

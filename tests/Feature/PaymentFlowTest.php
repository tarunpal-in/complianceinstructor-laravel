<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\RecordCapture;
use App\Checkout\BillingDetails;
use App\Contracts\PaymentGatewayDriver;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Entitlement;
use App\Models\Offering;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\CaptureResult;
use App\Payments\CheckoutIntent;
use App\Payments\PaymentGatewayManager;
use App\Payments\RefundResult;

/*
 * The pay-flow endpoints (P3). These drive the capture flow with a fake driver,
 * so the browser → start → capture → finalise path is proven without reaching a
 * real gateway; the money-correctness itself is the CapturePaymentTest hard gate.
 */

/** A gateway whose capture outcome the test chooses. */
class FakeCheckoutGateway implements PaymentGatewayDriver
{
    public function __construct(private bool $configured = true, private bool $succeeds = true) {}

    public function gateway(): PaymentGateway
    {
        return PaymentGateway::PayPal;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        return new CheckoutIntent('FAKE-ORDER-'.$order->getKey());
    }

    public function capture(Order $order, string $reference): CaptureResult
    {
        return $this->succeeds
            ? CaptureResult::succeeded('FAKE-CAP-'.$reference, $order->total_cents)
            : CaptureResult::failed('Card declined.');
    }

    public function refund(Payment $payment, int $amountCents, string $reason, string $idempotencyKey): RefundResult
    {
        return RefundResult::succeeded(null);
    }
}

function useFakeGateway(bool $configured = true, bool $succeeds = true): void
{
    app(PaymentGatewayManager::class)->register(new FakeCheckoutGateway($configured, $succeeds));
}

function payableOrder(User $buyer): Order
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

// The page ------------------------------------------------------------------

it('shows the payment page to the order owner', function (): void {
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)->get(route('checkout.pay', $order))
        ->assertSuccessful()
        ->assertSee($order->number);
});

it('404s the payment page for a stranger', function (): void {
    $order = payableOrder(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('checkout.pay', $order))
        ->assertNotFound();
});

it('sends an already-paid order straight to the receipt', function (): void {
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);
    app(RecordCapture::class)($order, PaymentGateway::PayPal, CaptureResult::succeeded('CAP-PAID', $order->total_cents));

    $this->actingAs($buyer)->get(route('checkout.pay', $order))
        ->assertRedirect(route('checkout.complete', $order));
});

// start ---------------------------------------------------------------------

it('starts a payment and returns the gateway order id', function (): void {
    useFakeGateway();
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.start', $order), ['gateway' => 'paypal'])
        ->assertSuccessful()
        ->assertJson(['gatewayOrderId' => 'FAKE-ORDER-'.$order->getKey()]);
});

it('refuses to start when the gateway is not configured', function (): void {
    useFakeGateway(configured: false);
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.start', $order), ['gateway' => 'paypal'])
        ->assertStatus(422);
});

it('refuses to start a payment for a gateway that is switched off', function (): void {
    // Stripe ships disabled; no driver is even resolved, the enabled check
    // refuses it first.
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.start', $order), ['gateway' => 'stripe'])
        ->assertStatus(422);
});

// complete ------------------------------------------------------------------

it('captures, marks paid, grants access, clears the cart and redirects', function (): void {
    useFakeGateway(succeeds: true);
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.complete', $order), ['gateway' => 'paypal', 'reference' => 'ORD1'])
        ->assertSuccessful()
        ->assertJson(['redirect' => route('checkout.complete', $order)]);

    expect($order->fresh()->derivedStatus())->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(Cart::query()->where('user_id', $buyer->id)->first()->items()->count())->toBe(0);
});

it('returns an error and grants nothing when the capture declines', function (): void {
    useFakeGateway(succeeds: false);
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.complete', $order), ['gateway' => 'paypal', 'reference' => 'ORD1'])
        ->assertStatus(422);

    expect($order->fresh()->derivedStatus())->not->toBe(OrderStatus::Paid)
        ->and(Entitlement::query()->count())->toBe(0);
});

it('refuses to complete without a payment reference', function (): void {
    useFakeGateway();
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.complete', $order), ['gateway' => 'paypal'])
        ->assertStatus(422);
});

it('refuses to complete through the manual gateway — the free-order bypass guard', function (): void {
    // The manual gateway has no admin enable toggle, so it must never be
    // selectable on the public capture endpoint. Without this guard a buyer could
    // POST gateway=manual and ManualDriver would report a free capture as
    // succeeded, granting access for $0 (§9 free-order bypass / §14 #2).
    $buyer = User::factory()->create();
    $order = payableOrder($buyer);

    $this->actingAs($buyer)
        ->postJson(route('checkout.pay.complete', $order), ['gateway' => 'manual', 'reference' => 'FREE'])
        ->assertStatus(422);

    expect($order->fresh()->derivedStatus())->not->toBe(OrderStatus::Paid)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Entitlement::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Actions\Payments\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Drivers\ManualDriver;
use App\Payments\Drivers\PayPalDriver;
use App\Payments\Drivers\StripeDriver;
use App\Payments\PaymentGatewayManager;
use Database\Seeders\RolePermissionSeeder;
use Tests\Support\FakeGatewayDriver;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** Put a fake driver in front of a gateway and hand it back for inspection. */
function fakeGateway(
    PaymentGateway $gateway = PaymentGateway::Stripe,
    bool $configured = true,
    ?string $declineWith = null,
): FakeGatewayDriver {
    $driver = new FakeGatewayDriver($gateway, $configured, $declineWith);

    app(PaymentGatewayManager::class)->register($driver);

    return $driver;
}

/** A captured payment on an order of the same size. */
function gatewayPayment(
    PaymentGateway $gateway = PaymentGateway::Stripe,
    int $cents = 20000,
): Payment {
    $order = Order::factory()->paid()->totalling($cents)->create();

    return Payment::factory()->create([
        'order_id' => $order->id,
        'gateway' => $gateway,
        'gateway_payment_id' => 'pi_'.fake()->lexify('????????????'),
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => $cents,
    ]);
}

// Wiring ----------------------------------------------------------------------

/**
 * §8 improvement #1 — one contract, three drivers. The registry is what lets a
 * refund be issued without any caller knowing which gateway took the money.
 */
it('registers a driver for every gateway', function (): void {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->driver(PaymentGateway::Stripe))->toBeInstanceOf(StripeDriver::class)
        ->and($manager->driver(PaymentGateway::PayPal))->toBeInstanceOf(PayPalDriver::class)
        ->and($manager->driver(PaymentGateway::Manual))->toBeInstanceOf(ManualDriver::class);
});

/**
 * A missing key has to say so before anything is written, not throw an SDK
 * exception halfway through a refund the admin believes they have issued.
 */
it('refuses to refund through a gateway with no credentials', function (): void {
    config(['services.stripe.secret' => null, 'cashier.secret' => null]);

    $payment = gatewayPayment();

    expect(fn () => app(RecordRefund::class)($payment, 5000, 'Nope'))
        ->toThrow(RuntimeException::class, 'not configured');

    expect($payment->fresh()?->refunds)->toHaveCount(0);
});

it('recognises when each driver is configured', function (): void {
    config([
        'services.stripe.secret' => null,
        'cashier.secret' => null,
        'services.paypal.client_id' => null,
        'services.paypal.client_secret' => null,
    ]);

    expect((new StripeDriver)->isConfigured())->toBeFalse()
        ->and((new PayPalDriver)->isConfigured())->toBeFalse()
        // Invoiced payments have nothing to configure — recording is always
        // possible, which is why they keep working before the keys arrive.
        ->and((new ManualDriver)->isConfigured())->toBeTrue();

    config([
        'services.stripe.secret' => 'sk_test_x',
        'services.paypal.client_id' => 'id',
        'services.paypal.client_secret' => 'secret',
    ]);

    expect((new StripeDriver)->isConfigured())->toBeTrue()
        ->and((new PayPalDriver)->isConfigured())->toBeTrue();
});

// Sending ---------------------------------------------------------------------

it('sends the refund to the gateway that took the money', function (PaymentGateway $gateway): void {
    $driver = fakeGateway($gateway);
    $payment = gatewayPayment($gateway);

    $refund = app(RecordRefund::class)($payment, 5000, 'One seat released');

    expect($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['amount'])->toBe(5000)
        ->and($driver->calls[0]['reason'])->toBe('One seat released')
        ->and($refund->status)->toBe(RefundStatus::Succeeded);
})->with([PaymentGateway::Stripe, PaymentGateway::PayPal]);

it('stores the reference the gateway gave back', function (): void {
    fakeGateway();
    $payment = gatewayPayment();

    $refund = app(RecordRefund::class)($payment, 5000, 'Released');

    // The whole point of capturing it: reconciling this row against the
    // gateway's own dashboard later.
    expect($refund->gateway_refund_id)->toBe('re_fake123')
        ->and($refund->processed_at)->not->toBeNull();
});

/**
 * §14 #5 — no unique constraint on the gateway id is what let the predecessor
 * double-grant on a replayed callback. The key is derived from the local row so
 * a retry after a timeout reaches the same gateway record.
 */
it('sends an idempotency key derived from the refund row', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment();

    $refund = app(RecordRefund::class)($payment, 5000, 'Released');

    expect($driver->calls[0]['key'])->toBe('refund-'.$refund->id);
});

it('records an invoiced refund with no gateway reference', function (): void {
    $payment = gatewayPayment(PaymentGateway::Manual);

    $refund = app(RecordRefund::class)($payment, 20000, 'Bank transfer returned');

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        // Inventing one would make a hand-recorded refund look confirmed by
        // somebody.
        ->and($refund->gateway_refund_id)->toBeNull()
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::Refunded);
});

// Declines --------------------------------------------------------------------

/**
 * A decline is an outcome, not an exception. The row stays as evidence the
 * attempt was made, and the order does not move — because the money did not.
 */
it('records a decline without moving the order', function (): void {
    fakeGateway(declineWith: 'The charge has already been disputed.');
    $payment = gatewayPayment();

    $refund = app(RecordRefund::class)($payment, 5000, 'Released');

    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->gateway_message)->toBe('The charge has already been disputed.')
        ->and($refund->processed_at)->toBeNull()
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::Paid);
});

/**
 * The admin's reason and the gateway's refusal are different statements and
 * neither may overwrite the other.
 */
it('keeps the reason and the refusal apart', function (): void {
    fakeGateway(declineWith: 'Balance too low to refund from.');
    $payment = gatewayPayment();

    $refund = app(RecordRefund::class)($payment, 5000, 'Customer changed their mind');

    expect($refund->reason)->toBe('Customer changed their mind')
        ->and($refund->gateway_message)->toBe('Balance too low to refund from.');
});

/** A failed refund releases its reservation, so the money can be tried again. */
it('lets a failed refund be retried in full', function (): void {
    fakeGateway(declineWith: 'Temporary gateway fault.');
    $payment = gatewayPayment(cents: 20000);

    app(RecordRefund::class)($payment, 20000, 'First try');

    expect($payment->fresh()?->refundableCents())->toBe(20000);

    fakeGateway();

    $second = app(RecordRefund::class)($payment->fresh(), 20000, 'Second try');

    expect($second->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->fresh()?->refundableCents())->toBe(0);
});

// The ceiling -----------------------------------------------------------------

it('still refuses more than remains', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    expect(fn () => app(RecordRefund::class)($payment, 25000, 'Too much'))
        ->toThrow(RuntimeException::class, 'more than remains refundable');

    expect($payment->fresh()?->refunds)->toHaveCount(0);
});

/**
 * A refund the gateway has not answered on yet holds its amount. Without that,
 * a second refund started while the first is in flight sees the full balance
 * and between them they return more than was ever taken.
 */
it('reserves an in-flight refund against the balance', function (): void {
    $payment = gatewayPayment(cents: 20000);

    $payment->refunds()->create([
        'status' => RefundStatus::Pending,
        'amount_cents' => 15000,
        'reason' => 'Still travelling',
    ]);

    $payment->load('refunds');

    expect($payment->refundableCents())->toBe(5000)
        // Not yet given back — only succeeded refunds count as returned.
        ->and($payment->refundedCents())->toBe(0);
});

// Through the screen ----------------------------------------------------------

it('refunds from the payment screen and says which gateway took it', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('refund', ['amount' => '50.00', 'reason' => 'One seat released']);

    expect($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['amount'])->toBe(5000)
        ->and($payment->fresh()?->refundedCents())->toBe(5000)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('surfaces a decline on the screen rather than reporting success', function (): void {
    fakeGateway(declineWith: 'The charge has already been disputed.');
    $payment = gatewayPayment(cents: 20000);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('refund', ['amount' => '50.00', 'reason' => 'Released'])
        ->assertNotified('The refund was refused');

    expect($payment->fresh()?->refundedCents())->toBe(0)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::Paid);
});

<?php

declare(strict_types=1);

use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\RecordCapture;
use App\Checkout\BillingDetails;
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
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/*
 * Refund → revoke access — CLAUDE.md §5.2 / §10. A full refund takes back
 * exactly the grants the order wrote; a partial refund leaves them, because it
 * cannot say which seat it was for.
 */

/**
 * A paid order with one claimed seat, its entitlement granted the real way.
 *
 * @return array{0: Order, 1: Payment, 2: User}
 */
function paidOrderWithGrant(int $cents = 20000): array
{
    $buyer = User::factory()->create();
    $offering = Offering::factory()->live()->create(['price_cents' => $cents]);
    $cart = Cart::factory()->forUser($buyer)->create(['currency' => 'USD']);
    CartItem::factory()->for($cart)->create(['offering_id' => $offering->id, 'quantity' => 1]);

    $order = app(PlaceOrder::class)(
        $cart->load('items.offering.formats', 'items.offering.offerable'),
        new BillingDetails((string) $buyer->name, (string) $buyer->email),
        $buyer,
        null,
        [['name' => (string) $buyer->name, 'email' => (string) $buyer->email]],
    );

    $payment = app(RecordCapture::class)(
        $order,
        PaymentGateway::Manual,
        CaptureResult::succeeded('cap_'.$order->getKey(), $order->total_cents, []),
    );

    return [$order->fresh(), $payment, $buyer];
}

it('revokes the order grants when the order is fully refunded', function (): void {
    [$order, $payment] = paidOrderWithGrant(20000);

    expect(Entitlement::query()->notRevoked()->count())->toBe(1);

    refundNow($payment, 20000, 'Changed their mind');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Entitlement::query()->notRevoked()->count())->toBe(0);

    // The row is revoked, not deleted — §5.2 keeps the ledger append-only.
    $grant = Entitlement::query()->firstOrFail();
    expect($grant->isRevoked())->toBeTrue()
        ->and($grant->note)->toContain($order->number);
});

it('leaves access in place on a partial refund', function (): void {
    [$order, $payment] = paidOrderWithGrant(20000);

    refundNow($payment, 5000, 'One of several seats');

    expect($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and(Entitlement::query()->notRevoked()->count())->toBe(1);
});

it('does not throw when a grant was already revoked by an admin', function (): void {
    [$order, $payment] = paidOrderWithGrant(20000);

    // Support revoked the one seat by hand before the refund swept through.
    app(App\Actions\Entitlements\RevokeEntitlement::class)(
        Entitlement::query()->firstOrFail(),
        'Manual revoke',
    );

    refundNow($payment, 20000, 'Full refund after manual revoke');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Entitlement::query()->notRevoked()->count())->toBe(0);
});

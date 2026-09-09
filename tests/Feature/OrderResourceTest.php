<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Authorization ---------------------------------------------------------------

it('opens the order list to roles that may read it', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/orders')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance, UserRole::Support]);

/**
 * Content Editor owns the catalogue and deliberately has no access to money —
 * §8 improvement #3 makes that a permission boundary rather than a convention.
 */
it('keeps the Content Editor out of orders entirely', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/orders')
        ->assertForbidden();
});

/**
 * Support resolves access problems and must not move money at all. Finance
 * handles the money that comes in — recording invoiced payments — but not the
 * money that goes back out: the client reserved refunding for the Super Admin.
 */
it('separates reading an order from moving money on it', function (): void {
    expect(userWithRole(UserRole::Support)->can('view_any_order'))->toBeTrue()
        ->and(userWithRole(UserRole::Support)->can('refund_payment'))->toBeFalse()
        ->and(userWithRole(UserRole::Support)->can('manage_manual_payment'))->toBeFalse()
        ->and(userWithRole(UserRole::Finance)->can('refund_payment'))->toBeFalse()
        ->and(userWithRole(UserRole::Finance)->can('manage_manual_payment'))->toBeTrue()
        ->and(userWithRole(UserRole::SuperAdmin)->can('refund_payment'))->toBeTrue();
});

/**
 * §14 #2 — the predecessor completed any cart for free by posting
 * `status=place_order`. There is no create page at all, so the admin cannot be
 * the thing that mints a paid order.
 */
it('offers no way to create an order by hand', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/orders/create')
        ->assertNotFound();
});

// Listing ---------------------------------------------------------------------

it('renders the list without lazy loading relations', function (): void {
    Order::factory()->count(4)->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/orders')
        ->assertSuccessful();
});

it('shows a guest order under the name captured at checkout', function (): void {
    $order = Order::factory()->guest()->create([
        'billing_snapshot' => ['name' => 'Dana Whitfield', 'email' => 'dana@example.test'],
    ]);

    expect($order->customerName())->toBe('Dana Whitfield')
        ->and($order->customerEmail())->toBe('dana@example.test');
});

it('filters orders to those awaiting payment', function (): void {
    $pending = Order::factory()->pending()->create();
    $paid = Order::factory()->paid()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListOrders::class)
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$paid]);
});

// Money -----------------------------------------------------------------------

/**
 * §9 — an order's status is derived from its payments, never from input. These
 * assert the derivation itself, which is the rule every other screen leans on.
 */
it('derives status from the payment rows', function (
    array $payments,
    array $refunds,
    OrderStatus $expected,
): void {
    $order = Order::factory()->totalling(20000)->create();

    foreach ($payments as [$status, $cents]) {
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'status' => $status,
            'amount_cents' => $cents,
        ]);

        foreach ($refunds as $refundCents) {
            Refund::factory()->create([
                'payment_id' => $payment->id,
                'status' => RefundStatus::Succeeded,
                'amount_cents' => $refundCents,
            ]);
        }
    }

    expect($order->fresh()?->load('payments.refunds')->derivedStatus())->toBe($expected);
})->with([
    'nothing captured' => [[], [], OrderStatus::Pending],
    'captured in full' => [[[PaymentStatus::Succeeded, 20000]], [], OrderStatus::Paid],
    'capture failed' => [[[PaymentStatus::Failed, 20000]], [], OrderStatus::Failed],
    'refunded in full' => [[[PaymentStatus::Succeeded, 20000]], [20000], OrderStatus::Refunded],
    'refunded in part' => [[[PaymentStatus::Succeeded, 20000]], [5000], OrderStatus::PartiallyRefunded],
]);

it('counts only succeeded payments as captured', function (): void {
    $order = Order::factory()->totalling(20000)->create();

    Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Succeeded, 'amount_cents' => 12000]);
    Payment::factory()->pending()->create(['order_id' => $order->id, 'amount_cents' => 8000]);
    Payment::factory()->failed()->create(['order_id' => $order->id, 'amount_cents' => 8000]);

    $order->load('payments.refunds');

    expect($order->capturedCents())->toBe(12000)
        ->and($order->outstandingCents())->toBe(8000);
});

/**
 * A stored status that no longer agrees with the payments means a webhook was
 * missed or someone edited the row. Making it visible beats discovering it from
 * a support ticket.
 */
it('reports when the stored status disagrees with the payments', function (): void {
    $order = Order::factory()->paid()->totalling(20000)->create();
    $order->load('payments.refunds');

    expect($order->statusMatchesPayments())->toBeFalse();

    Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 20000,
    ]);

    expect($order->fresh()?->load('payments.refunds')->statusMatchesPayments())->toBeTrue();
});

it('re-derives a mismatched status from the view page', function (): void {
    $order = Order::factory()->pending()->totalling(20000)->create();
    Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 20000,
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('reconcile');

    expect($order->fresh()?->status)->toBe(OrderStatus::Paid);
});

// Refunds ---------------------------------------------------------------------

/**
 * Refunding more than was taken only surfaces weeks later in a bank
 * reconciliation, so the ceiling is enforced server-side rather than left to
 * whoever fills the form.
 */
it('refuses to refund more than remains on the payment', function (): void {
    fakeGateway();
    $order = Order::factory()->paid()->totalling(20000)->create();
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 20000,
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->callTableAction('refund', $payment, ['amount' => '250.00', 'reason' => 'Too much'])
        ->assertHasTableActionErrors(['amount']);

    expect($payment->fresh()?->refunds)->toHaveCount(0);
});

/**
 * The order screen mounts the payment screen's action, so a refund raised here
 * gets the same cooling-off window rather than going out immediately.
 */
it('schedules a partial refund from the order screen', function (): void {
    fakeGateway();
    $order = Order::factory()->paid()->totalling(20000)->create();
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 20000,
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->callTableAction('refund', $payment, ['amount' => '50.00', 'reason' => 'One seat released']);

    // Claimed but not yet given back, and the order has not moved.
    expect($payment->fresh()?->refundableCents())->toBe(15000)
        ->and($payment->fresh()?->refundedCents())->toBe(0)
        ->and($order->fresh()?->status)->toBe(OrderStatus::Paid);

    $this->travel(61)->minutes();
    $this->artisan('refunds:release-due');

    expect($payment->fresh()?->refundedCents())->toBe(5000)
        ->and($order->fresh()?->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('offers no refund on a payment that never succeeded', function (): void {
    $payment = Payment::factory()->failed()->create();

    expect($payment->refundableCents())->toBe(0);
});

// Manual payments -------------------------------------------------------------

/**
 * This audience is B2B and frequently invoiced (§1), so a bank transfer has to
 * be recordable — but it is the only path that marks an order paid without a
 * gateway confirming it, hence its own permission.
 */
it('records an invoiced payment and settles the order', function (): void {
    $order = Order::factory()->pending()->totalling(27900)->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        // A table header action, so no record — callAction() alone looks only at
        // the page's own actions and would not find it.
        ->callTableAction('create', null, [
            'reference' => 'BACS 44120099',
            'amount' => '279.00',
            'processed_at' => now(),
        ]);

    $payment = $order->fresh()?->payments->first();

    expect($payment?->gateway)->toBe(PaymentGateway::Manual)
        ->and($payment?->status)->toBe(PaymentStatus::Succeeded)
        // Parsed as a string, never multiplied as a float: (float) '279.00' * 100
        // is not reliably 27900 (§4 rule 6).
        ->and($payment?->amount_cents)->toBe(27900)
        ->and($payment?->gateway_payment_id)->toBeNull()
        ->and($order->fresh()?->status)->toBe(OrderStatus::Paid);
});

/**
 * §14 #5 — no unique constraint on the gateway payment id meant a replayed
 * return URL could double-grant. Several manual payments must still coexist,
 * which they do because SQL treats NULLs as distinct.
 */
it('refuses two payments sharing a gateway id but allows many manual ones', function (): void {
    $order = Order::factory()->create();

    Payment::factory()->create(['order_id' => $order->id, 'gateway_payment_id' => 'pi_duplicate']);

    expect(fn () => Payment::factory()->create([
        'order_id' => $order->id,
        'gateway_payment_id' => 'pi_duplicate',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);

    Payment::factory()->manual()->count(3)->create(['order_id' => $order->id]);

    expect($order->fresh()?->payments)->toHaveCount(4);
});

/**
 * §9 — the predecessor had no audit trail. A manual payment has no gateway
 * confirmation behind it, so this log is the only evidence of who decided an
 * invoice had been settled.
 */
it('logs who recorded an invoiced payment', function (): void {
    $finance = userWithRole(UserRole::Finance);
    $order = Order::factory()->pending()->totalling(19900)->create();

    Livewire\Livewire::actingAs($finance)
        ->test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
        ->callTableAction('create', null, [
            'reference' => 'BACS 90011122',
            'amount' => '199.00',
            'processed_at' => now(),
        ]);

    $entry = Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'payment')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->causer_id)->toBe($finance->id)
        ->and($order->fresh()?->payments->first()?->recorded_by)->toBe($finance->id);
});

// Lines -----------------------------------------------------------------------

/**
 * §14 #7 — the predecessor stored a unit price in a column called
 * `total_price`, so every revenue sum was wrong by a factor of quantity.
 */
it('keeps the line total as unit price times quantity', function (): void {
    $item = OrderItem::factory()->create([
        'quantity' => 3,
        'unit_price_cents' => 19900,
        'line_total_cents' => 59700,
    ]);

    expect($item->totalIsConsistent())->toBeTrue()
        ->and($item->seatCount())->toBe(3);

    $item->update(['line_total_cents' => 19900]);

    expect($item->fresh()?->totalIsConsistent())->toBeFalse();
});

/**
 * docs/webinars-module.md §2.1 — an offering sells bundles, §5.2 keys
 * entitlements per format, so a line has to record every format it granted or
 * a refund cannot revoke exactly what was given.
 */
it('snapshots every format a bundle line granted', function (): void {
    $item = OrderItem::factory()
        ->granting([App\Enums\Format::Live, App\Enums\Format::Recorded, App\Enums\Format::Transcript])
        ->create();

    expect($item->formatEnums())->toHaveCount(3)
        ->and($item->fresh()?->description)->toBe('Live Webinar + Recorded + Transcript (PDF)');
});

/**
 * Numbers come from a stored counter, not from reading back the highest
 * existing order — so writing a row with a number of its own does not move the
 * sequence. That is what keeps a number issued on an invoice from ever being
 * reissued. The format itself is the client's to set; OrderNumberTest covers it.
 */
it('numbers orders from the counter rather than from existing rows', function (): void {
    $this->seed(Database\Seeders\SettingSeeder::class);

    expect(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0001');

    Order::factory()->create(['number' => 'CI-'.now()->format('Y').'-0007']);

    expect(Order::nextNumber())->toBe('CI-'.now()->format('Y').'-0002');
});

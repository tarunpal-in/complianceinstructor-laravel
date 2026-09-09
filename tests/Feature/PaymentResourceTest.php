<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Authorization ---------------------------------------------------------------

it('opens the payment log to roles that may read it', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/payments')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance, UserRole::Support]);

/**
 * Content Editor owns the catalogue and has no access to money — §8 improvement
 * #3 makes that a permission boundary rather than a convention.
 */
it('keeps the Content Editor out of the payment log', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/payments')
        ->assertForbidden();
});

/**
 * A payment is evidence that money moved. There is no page that creates,
 * edits or deletes one — a mistaken payment is corrected by a refund, which
 * leaves both entries on the record.
 */
it('offers no way to create or edit a payment', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/payments/create')
        ->assertNotFound();

    $payment = capturedPayment();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/payments/'.$payment->id.'/edit')
        ->assertNotFound();
});

// Listing ---------------------------------------------------------------------

it('renders the log without lazy loading relations', function (): void {
    capturedPayment();
    Payment::factory()->paypal()->create(['order_id' => Order::factory()->paid()->create()->id]);
    Payment::factory()->manual()->create(['order_id' => Order::factory()->paid()->create()->id]);

    $this->actingAs(userWithRole(UserRole::Finance))
        ->get('/admin/payments')
        ->assertSuccessful();
});

/**
 * §8 improvement #1 — both gateways write to the same table so reporting never
 * branches. This screen is the point of that.
 */
it('shows every gateway in one list', function (): void {
    $stripe = capturedPayment();
    $paypal = Payment::factory()->paypal()->create(['order_id' => Order::factory()->paid()->create()->id]);
    $manual = Payment::factory()->manual()->create(['order_id' => Order::factory()->paid()->create()->id]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListPayments::class)
        ->assertCanSeeTableRecords([$stripe, $paypal, $manual]);
});

it('separates the invoiced payments onto their own tab', function (): void {
    $card = capturedPayment();
    $invoiced = Payment::factory()->manual()->create(['order_id' => Order::factory()->paid()->create()->id]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListPayments::class)
        ->set('activeTab', 'manual')
        ->assertCanSeeTableRecords([$invoiced])
        ->assertCanNotSeeTableRecords([$card]);
});

it('filters to payments that have been refunded', function (): void {
    $refunded = capturedPayment();
    Refund::factory()->create(['payment_id' => $refunded->id, 'amount_cents' => 5000]);

    $untouched = capturedPayment();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListPayments::class)
        ->filterTable('refunded', true)
        ->assertCanSeeTableRecords([$refunded])
        ->assertCanNotSeeTableRecords([$untouched]);
});

/**
 * A manual payment has no gateway behind it, so who recorded it is the only
 * evidence the money arrived (§9). One with nobody against it is worth finding.
 */
it('finds payments whose record is incomplete', function (): void {
    $orphaned = Payment::factory()->manual()->create([
        'order_id' => Order::factory()->paid()->create()->id,
        'recorded_by' => null,
    ]);

    $accounted = Payment::factory()->manual()->create([
        'order_id' => Order::factory()->paid()->create()->id,
        'recorded_by' => userWithRole(UserRole::Finance)->id,
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListPayments::class)
        ->filterTable('incomplete_record', true)
        ->assertCanSeeTableRecords([$orphaned])
        ->assertCanNotSeeTableRecords([$accounted]);
});

// Refunding -------------------------------------------------------------------

// The happy path through this screen — schedule, then release — is covered in
// GatewayRefundTest, which owns the cooling-off window.

it('refuses to refund more than remains, from the payment screen too', function (): void {
    fakeGateway();
    $payment = capturedPayment(20000);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('refund', ['amount' => '250.00', 'reason' => 'Too much'])
        ->assertHasActionErrors(['amount']);

    expect($payment->fresh()?->refunds)->toHaveCount(0);
});

it('hides the refund action from Support', function (): void {
    $payment = capturedPayment();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertActionHidden('refund');
});

/**
 * Two screens offering a slightly different refund is how a bank reconciliation
 * ends up disagreeing with the database, so both call the same action. This
 * asserts the action itself enforces the ceiling, not just the form on top.
 */
it('caps the refund inside the action, not only in the form', function (): void {
    fakeGateway();
    $payment = capturedPayment(20000);

    expect(fn () => refundNow($payment, 25000, 'Too much'))
        ->toThrow(RuntimeException::class, 'more than remains refundable');

    expect($payment->fresh()?->refunds)->toHaveCount(0);
});

it('refuses a refund of nothing', function (): void {
    fakeGateway();
    $payment = capturedPayment(20000);

    expect(fn () => refundNow($payment, 0, 'Nothing'))
        ->toThrow(RuntimeException::class);
});

it('accumulates partial refunds rather than overwriting them', function (): void {
    fakeGateway();
    $payment = capturedPayment(20000);
    $action = refundNow(...);

    $action($payment, 5000, 'First seat');
    $action($payment->fresh(), 5000, 'Second seat');

    expect($payment->fresh()?->refundedCents())->toBe(10000)
        ->and($payment->fresh()?->refunds)->toHaveCount(2)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('moves the order to fully refunded once nothing remains', function (): void {
    fakeGateway();
    $payment = capturedPayment(20000);

    refundNow($payment, 20000, 'Cancelled before the session');

    expect($payment->fresh()?->refundableCents())->toBe(0)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::Refunded);
});

it('will not refund a payment that never succeeded', function (): void {
    fakeGateway();
    $payment = Payment::factory()->failed()->create([
        'order_id' => Order::factory()->create()->id,
        'amount_cents' => 20000,
    ]);

    expect($payment->refundableCents())->toBe(0)
        ->and(fn () => refundNow($payment, 100, 'Nope'))
        ->toThrow(RuntimeException::class);
});

// Money -----------------------------------------------------------------------

/**
 * §4 rule 6 — never float arithmetic on a price. `(float) '19.99' * 100` is
 * 1998.9999999999998, which truncates to 1998 and shorts the customer a penny
 * forever without ever failing loudly.
 */
it('converts decimals to cents without floating point', function (string $input, int $cents): void {
    expect(Money::toCents($input))->toBe($cents);
})->with([
    'whole' => ['279', 27900],
    'two places' => ['279.00', 27900],
    'the classic' => ['19.99', 1999],
    'one place is tenths' => ['279.5', 27950],
    'more places truncate, never round up' => ['19.999', 1999],
    'thousands separators are ignored' => ['1,299.50', 129950],
    'a currency symbol is ignored' => ['$49.95', 4995],
    'negative' => ['-19.99', -1999],
]);

it('converts cents back to a decimal string', function (): void {
    expect(Money::toDecimal(27900))->toBe('279.00')
        ->and(Money::toDecimal(1999))->toBe('19.99')
        ->and(Money::toDecimal(5))->toBe('0.05')
        ->and(Money::toDecimal(0))->toBe('0.00')
        ->and(Money::toDecimal(-1999))->toBe('-19.99');
});

it('round-trips every amount it is given', function (int $cents): void {
    expect(Money::toCents(Money::toDecimal($cents)))->toBe($cents);
})->with([0, 5, 99, 100, 1999, 27900, 129950, 99999999]);

it('keeps null meaning null for an optional field', function (): void {
    expect(Money::toOptionalCents(null))->toBeNull()
        ->and(Money::toOptionalCents(''))->toBeNull()
        ->and(Money::toOptionalCents('19.99'))->toBe(1999)
        ->and(Money::toOptionalDecimal(null))->toBe('')
        ->and(Money::toOptionalDecimal(1999))->toBe('19.99');
});

it('formats for display outside a money column', function (): void {
    expect(Money::format(27900))->toBe('$279.00')
        ->and(Money::format(27900, 'GBP'))->toBe('£279.00')
        ->and(Money::format(0))->toBe('$0.00');
});

it('labels the gateway a manual payment came through', function (): void {
    expect(PaymentGateway::Manual->isManual())->toBeTrue()
        ->and(PaymentGateway::Stripe->isManual())->toBeFalse()
        ->and(PaymentGateway::Manual->label())->toBe('Manual / invoice');
});

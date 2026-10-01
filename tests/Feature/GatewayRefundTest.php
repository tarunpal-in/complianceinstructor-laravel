<?php

declare(strict_types=1);

use App\Actions\Payments\CancelScheduledRefund;
use App\Actions\Payments\RequestRefund;
use App\Actions\Payments\SendRefundToGateway;
use App\Enums\EmailStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\RelationManagers\RefundsRelationManager;
use App\Models\EmailSend;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Payments\Drivers\ManualDriver;
use App\Payments\Drivers\PayPalDriver;
use App\Payments\Drivers\StripeDriver;
use App\Payments\PaymentGatewayManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

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

    expect(fn () => refundNow($payment, 5000, 'Nope'))
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

    $refund = refundNow($payment, 5000, 'One seat released');

    expect($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['amount'])->toBe(5000)
        ->and($driver->calls[0]['reason'])->toBe('One seat released')
        ->and($refund->status)->toBe(RefundStatus::Succeeded);
})->with([PaymentGateway::Stripe, PaymentGateway::PayPal]);

it('stores the reference the gateway gave back', function (): void {
    fakeGateway();
    $payment = gatewayPayment();

    $refund = refundNow($payment, 5000, 'Released');

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

    $refund = refundNow($payment, 5000, 'Released');

    expect($driver->calls[0]['key'])->toBe('refund-'.$refund->id);
});

it('records an invoiced refund with no gateway reference', function (): void {
    $payment = gatewayPayment(PaymentGateway::Manual);

    $refund = refundNow($payment, 20000, 'Bank transfer returned');

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

    $refund = refundNow($payment, 5000, 'Released');

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

    $refund = refundNow($payment, 5000, 'Customer changed their mind');

    expect($refund->reason)->toBe('Customer changed their mind')
        ->and($refund->gateway_message)->toBe('Balance too low to refund from.');
});

/** A failed refund releases its reservation, so the money can be tried again. */
it('lets a failed refund be retried in full', function (): void {
    fakeGateway(declineWith: 'Temporary gateway fault.');
    $payment = gatewayPayment(cents: 20000);

    refundNow($payment, 20000, 'First try');

    expect($payment->fresh()?->refundableCents())->toBe(20000);

    fakeGateway();

    $second = refundNow($payment->fresh(), 20000, 'Second try');

    expect($second->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->fresh()?->refundableCents())->toBe(0);
});

// The ceiling -----------------------------------------------------------------

it('still refuses more than remains', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    expect(fn () => refundNow($payment, 25000, 'Too much'))
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

// The cooling-off window ------------------------------------------------------

/**
 * Refunding is the one admin action that moves money out irreversibly, and §9's
 * threat model includes an admin account being used by someone else. An hour
 * and an email is the difference between catching that and reading about it on
 * a bank statement.
 */
it('schedules a refund rather than sending it', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    $refund = app(RequestRefund::class)($payment, 5000, 'One seat released');

    expect($refund->status)->toBe(RefundStatus::Scheduled)
        ->and($refund->scheduled_for?->diffInMinutes(now()))->toBeLessThanOrEqual(60)
        // Nothing has been sent, and the order has not moved.
        ->and($driver->calls)->toBeEmpty()
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::Paid);
});

/*
 * Asserted against `email_sends` rather than Notification::fake, because the
 * notification became a mailable through the Dispatcher — and the log row is now
 * the thing that matters. A faked notification proves the code was called; a row
 * proves somebody could find out afterwards whether it went.
 */
it('emails every administrator when a refund is scheduled', function (): void {
    fakeGateway();

    $admins = User::factory()->count(2)->create();
    foreach ($admins as $admin) {
        $admin->assignRole(UserRole::SuperAdmin->value);
    }

    app(RequestRefund::class)(gatewayPayment(), 5000, 'Released');

    foreach ($admins as $admin) {
        expect(EmailSend::query()
            ->where('email_key', 'internal.refund_scheduled')
            ->where('to_email', $admin->email)
            ->where('status', EmailStatus::Sent)
            ->count())->toBe(1);
    }
});

/*
 * Until now only staff heard about a refund. A credit landing on a card
 * statement with a merchant descriptor and no explanation is a support ticket at
 * best and a chargeback dispute at worst.
 */
it('tells the customer once the refund actually reaches the gateway', function (): void {
    fakeGateway();

    $payment = gatewayPayment();
    $refund = app(RequestRefund::class)($payment, 5000, 'Duplicate booking');

    app(SendRefundToGateway::class)($refund);

    $send = EmailSend::query()->where('email_key', 'purchase.refund_issued')->sole();

    expect($send->to_email)->toBe($payment->order->customerEmail())
        ->and($send->status)->toBe(EmailStatus::Sent)
        ->and($send->related_id)->toBe($payment->order->getKey());
});

/*
 * A refund the gateway refused has not happened. Telling the customer money is
 * on its way would be worse than saying nothing.
 */
it('tells the customer nothing when the gateway refuses the refund', function (): void {
    fakeGateway(declineWith: 'Balance too low to refund from.');

    $refund = app(RequestRefund::class)(gatewayPayment(), 5000, 'Duplicate booking');

    app(SendRefundToGateway::class)($refund);

    expect(EmailSend::query()->where('email_key', 'purchase.refund_issued')->count())->toBe(0);
});

/**
 * Finance reads every payment and records invoiced ones, but cannot send money
 * back — the client reserved that for the Super Admin.
 */
it('grants the refund permission to the Super Admin alone', function (): void {
    expect(userWithRole(UserRole::SuperAdmin)->can('refund_payment'))->toBeTrue()
        ->and(userWithRole(UserRole::Finance)->can('refund_payment'))->toBeFalse()
        ->and(userWithRole(UserRole::Support)->can('refund_payment'))->toBeFalse()
        // Finance keeps the rest of its work.
        ->and(userWithRole(UserRole::Finance)->can('manage_manual_payment'))->toBeTrue()
        ->and(userWithRole(UserRole::Finance)->can('view_any_payment'))->toBeTrue();
});

it('hides the refund action from Finance', function (): void {
    fakeGateway();
    $payment = gatewayPayment();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertActionHidden('refund');
});

/** A scheduled refund holds its amount, so a second one cannot claim it too. */
it('reserves a scheduled refund against the balance', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 15000, 'First');

    expect($payment->fresh()?->refundableCents())->toBe(5000)
        ->and($payment->fresh()?->refundedCents())->toBe(0);
});

it('sends nothing until the window has closed', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 5000, 'Released');

    $this->artisan('refunds:release-due')->assertSuccessful();

    expect($driver->calls)->toBeEmpty();

    $this->travel(61)->minutes();

    $this->artisan('refunds:release-due')->assertSuccessful();

    expect($driver->calls)->toHaveCount(1)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('takes the window length from settings', function (): void {
    Setting::set('orders.refund_delay_minutes', 5, 'integer');
    fakeGateway();

    $refund = app(RequestRefund::class)(gatewayPayment(), 5000, 'Released');

    expect(RequestRefund::delayMinutes())->toBe(5)
        ->and($refund->scheduled_for?->diffInMinutes(now()))->toBeLessThanOrEqual(5);
});

/**
 * The sweep is idempotent: a second run must find the refund already claimed
 * and leave it alone rather than refunding twice.
 */
it('does not send the same refund twice', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 5000, 'Released');
    $this->travel(61)->minutes();

    $this->artisan('refunds:release-due');
    $this->artisan('refunds:release-due');

    expect($driver->calls)->toHaveCount(1);
});

// One refund per order at a time ----------------------------------------------

/**
 * The per-payment ceiling stops an order being refunded for more than it took,
 * but not two administrators each refunding a *different* payment of the same
 * order inside the same hour. Both are individually valid, and between them the
 * customer gets back twice what either intended.
 */
it('refuses a second refund while one is in flight on the same order', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 5000, 'First');

    expect(fn () => app(RequestRefund::class)($payment->fresh(), 5000, 'Second'))
        ->toThrow(RuntimeException::class, 'has not been sent yet');
});

/** The case the per-payment ceiling cannot see. */
it('refuses a refund on a sibling payment of the same order', function (): void {
    fakeGateway();
    $order = Order::factory()->paid()->totalling(20000)->create();

    $card = Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 10000,
    ]);

    $invoiced = Payment::factory()->manual()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 10000,
    ]);

    app(RequestRefund::class)($card, 10000, 'Refunding the card half');

    expect(fn () => app(RequestRefund::class)($invoiced, 10000, 'And the invoiced half'))
        ->toThrow(RuntimeException::class, $order->number);
});

/**
 * Refunds on *different* orders are unrelated and must not block each other.
 */
it('allows a refund on another order while one is in flight', function (): void {
    fakeGateway();

    app(RequestRefund::class)(gatewayPayment(cents: 20000), 5000, 'First order');
    $second = app(RequestRefund::class)(gatewayPayment(cents: 20000), 5000, 'Second order');

    expect($second->status)->toBe(RefundStatus::Scheduled);
});

/**
 * The block is on the window, not on refunding twice. One seat this week and
 * another next month is normal, and the ceiling keeps it honest.
 */
it('allows a second partial refund once the first has gone out', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 5000, 'First seat');
    $this->travel(61)->minutes();
    $this->artisan('refunds:release-due');

    $second = app(RequestRefund::class)($payment->fresh(), 5000, 'Second seat');

    expect($second->status)->toBe(RefundStatus::Scheduled)
        ->and($payment->fresh()?->refundedCents())->toBe(5000)
        ->and($payment->fresh()?->refundableCents())->toBe(10000);
});

it('frees the order again once the in-flight refund is cancelled', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    $first = app(RequestRefund::class)($payment, 20000, 'Wrong figure');
    app(CancelScheduledRefund::class)($first);

    $corrected = app(RequestRefund::class)($payment->fresh(), 5000, 'Correct figure');

    expect($corrected->status)->toBe(RefundStatus::Scheduled);
});

/** A failed refund resolves the order too — it can be tried again. */
it('frees the order again after a refund is declined', function (): void {
    fakeGateway(declineWith: 'Temporary gateway fault.');
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 5000, 'First try');
    $this->travel(61)->minutes();
    $this->artisan('refunds:release-due');

    fakeGateway();

    expect(app(RequestRefund::class)($payment->fresh(), 5000, 'Second try')->status)
        ->toBe(RefundStatus::Scheduled);
});

it('says so on the screen rather than throwing', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    app(RequestRefund::class)($payment, 5000, 'Already in flight');

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('refund', ['amount' => '50.00', 'reason' => 'Another'])
        ->assertNotified('That refund was not raised');

    expect($payment->fresh()?->refunds)->toHaveCount(1);
});

// Cancelling ------------------------------------------------------------------

/**
 * The window is worth nothing without this. A delay nobody can act on just
 * makes refunds slower.
 */
it('stops a scheduled refund and releases the amount', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    $refund = app(RequestRefund::class)($payment, 5000, 'Raised in error');

    $cancelled = app(CancelScheduledRefund::class)($refund, userWithRole(UserRole::SuperAdmin)->id);

    expect($cancelled->status)->toBe(RefundStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancelled_by)->not->toBeNull()
        ->and($payment->fresh()?->refundableCents())->toBe(20000);

    $this->travel(61)->minutes();
    $this->artisan('refunds:release-due');

    expect($driver->calls)->toBeEmpty();
});

it('refuses to cancel a refund that has already gone', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    $refund = refundNow($payment, 5000, 'Released');

    expect(fn () => app(CancelScheduledRefund::class)($refund))
        ->toThrow(RuntimeException::class, 'already been sent');
});

it('lets a cancelled refund be replaced by a corrected one', function (): void {
    fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    $wrong = app(RequestRefund::class)($payment, 20000, 'Wrong figure');
    app(CancelScheduledRefund::class)($wrong);

    $right = app(RequestRefund::class)($payment->fresh(), 5000, 'Correct figure');

    expect($right->status)->toBe(RefundStatus::Scheduled)
        ->and($payment->fresh()?->refundableCents())->toBe(15000);
});

// Through the screen ----------------------------------------------------------

/**
 * The screen says what actually happened. Reporting "refund sent" here would be
 * a lie for the next hour, and the kind that only surfaces when a customer asks
 * where their money is.
 */
it('schedules from the payment screen and says so', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('refund', ['amount' => '50.00', 'reason' => 'One seat released'])
        ->assertNotified('Refund scheduled');

    expect($driver->calls)->toBeEmpty()
        ->and($payment->fresh()?->refunds->first()?->status)->toBe(RefundStatus::Scheduled)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::Paid);

    $this->travel(61)->minutes();
    $this->artisan('refunds:release-due');

    expect($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['amount'])->toBe(5000)
        ->and($payment->fresh()?->refundedCents())->toBe(5000)
        ->and($payment->order->fresh()?->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('cancels a scheduled refund from the refunds list', function (): void {
    $driver = fakeGateway();
    $payment = gatewayPayment(cents: 20000);

    $refund = app(RequestRefund::class)($payment, 5000, 'Raised in error');

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(RefundsRelationManager::class, ['ownerRecord' => $payment, 'pageClass' => ViewPayment::class])
        ->callTableAction('cancelRefund', $refund)
        ->assertNotified('Refund cancelled');

    $this->travel(61)->minutes();
    $this->artisan('refunds:release-due');

    expect($refund->fresh()?->status)->toBe(RefundStatus::Cancelled)
        ->and($driver->calls)->toBeEmpty()
        ->and($payment->fresh()?->refundableCents())->toBe(20000);
});

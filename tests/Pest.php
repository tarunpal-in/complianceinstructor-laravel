<?php

declare(strict_types=1);

use App\Actions\Payments\RequestRefund;
use App\Actions\Payments\SendRefundToGateway;
use App\Enums\Format;
use App\Enums\OrderNumberReset;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Webinars\Pages\EditWebinar;
use App\Filament\Resources\Webinars\RelationManagers\OfferingsRelationManager;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offering;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webinar;
use App\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGatewayDriver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| Every helper used by more than one test file lives here, and this file is
| loaded by every process.
|
| They were previously declared inside whichever test file first needed one.
| That works while the suite runs in a single process — PHP has loaded every
| file by the time anything executes — and breaks the moment it runs in
| parallel, because a worker given WebinarResourceTest has never loaded the
| file that happened to declare userWithRole().
|
*/

/** An admin of a given role, for the authorization tests every resource has. */
function userWithRole(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

// Catalogue -------------------------------------------------------------------

/**
 * A webinar the public detail page will actually render.
 *
 * Content lives in `webinar_sections` now, so pass `$sections` to control the
 * blocks; the default is an overview plus a highlights block.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>|null  $sections
 */
function publishedWebinar(array $attributes = [], ?array $sections = null): Webinar
{
    $webinar = Webinar::factory()->scheduled()->create([
        'title' => 'FMLA Compliance 2026: Avoiding Costly Mistakes',
        'summary' => 'Discover how the H.O.P.E. framework reshapes leadership culture.',
        ...$attributes,
    ]);

    $sections ??= [
        ['heading' => 'Webinar Overview', 'body' => "First overview paragraph.\n\nSecond overview paragraph."],
        ['heading' => 'Session Highlights', 'body' => 'What the session covers.'],
    ];

    foreach ($sections as $order => $section) {
        $webinar->sections()->create([
            'heading' => $section['heading'] ?? null,
            'body' => $section['body'] ?? '',
            'sort_order' => $order,
            'is_visible' => $section['is_visible'] ?? true,
        ]);
    }

    return $webinar;
}

/** A single-format offering of a webinar, with an optional add-on price (§5.3). */
function fmtOffering(Webinar $webinar, Format $format, int $price, ?int $addon = null): Offering
{
    return Offering::factory()->withFormats($format)->create([
        'offerable_type' => Webinar::class,
        'offerable_id' => $webinar->getKey(),
        'price_cents' => $price,
        'addon_price_cents' => $addon,
        'is_active' => true,
    ]);
}

/**
 * A cart built from offering/quantity pairs, relations loaded for pricing.
 *
 * @param  list<array{0: Offering, 1: int}>  $lines
 */
function cartOf(array $lines): Cart
{
    $cart = Cart::factory()->create();

    foreach ($lines as [$offering, $qty]) {
        CartItem::factory()->for($cart)->create(['offering_id' => $offering->getKey(), 'quantity' => $qty]);
    }

    return $cart->load('items.offering.formats', 'items.offering.offerable');
}

/** The offerings relation manager, mounted on a webinar. */
function manager(Webinar $webinar): Livewire\Features\SupportTesting\Testable
{
    return Livewire\Livewire::test(OfferingsRelationManager::class, [
        'ownerRecord' => $webinar,
        'pageClass' => EditWebinar::class,
    ]);
}

// Order numbering -------------------------------------------------------------

/** Point the settings at a numbering format without going through the form. */
function useFormat(
    string $pattern,
    int $padding,
    OrderNumberReset $reset,
    int $yearStartMonth = 1,
): void {
    Setting::set('orders.number_format', $pattern, 'string');
    Setting::set('orders.number_padding', $padding, 'integer');
    Setting::set('orders.number_reset', $reset->value, 'string');
    Setting::set('orders.year_start_month', $yearStartMonth, 'integer');
}

// Payments --------------------------------------------------------------------

/** A captured payment against an order of the same size. */
function capturedPayment(int $cents = 20000, array $attributes = []): Payment
{
    $order = Order::factory()->paid()->totalling($cents)->create();

    return Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => $cents,
        ...$attributes,
    ]);
}

/** As above, but through a named gateway and with a gateway reference. */
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

/**
 * Agree a refund and immediately release it, for tests about the gateway call.
 *
 * The cooling-off window is real behaviour with its own tests; this
 * short-circuits it so a test about, say, the idempotency key does not have to
 * travel an hour to get there.
 */
function refundNow(Payment $payment, int $cents, string $reason = 'Released'): Refund
{
    $refund = app(RequestRefund::class)($payment, $cents, $reason);

    return app(SendRefundToGateway::class)($refund);
}

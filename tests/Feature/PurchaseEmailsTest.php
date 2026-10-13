<?php

declare(strict_types=1);

use App\Actions\Orders\GenerateInvoicePdf;
use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\RecordCapture;
use App\Checkout\BillingDetails;
use App\Enums\EmailStatus;
use App\Enums\PaymentGateway;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EmailSend;
use App\Models\Offering;
use App\Models\Order;
use App\Models\User;
use App\Payments\CaptureResult;
use App\Support\Money;

/*
 * The P3 purchase emails (§14 #6 / §15) and the invoice PDF (§8 / §9). The money
 * core they hang off is tested elsewhere; this is about who hears what, once.
 */

/**
 * Place an order for `$seats` of one live offering, naming each seat from
 * `$attendees`, and hand back the pending order.
 *
 * @param  array<int, array{name: string, email: string}>  $attendees
 */
function pendingOrderFor(User $buyer, array $attendees, int $seats = 1, int $cents = 20000): Order
{
    $offering = Offering::factory()->live()->create(['price_cents' => $cents]);
    $cart = Cart::factory()->forUser($buyer)->create(['currency' => 'USD']);
    CartItem::factory()->for($cart)->create(['offering_id' => $offering->id, 'quantity' => $seats]);

    return app(PlaceOrder::class)(
        $cart->load('items.offering.formats', 'items.offering.offerable'),
        new BillingDetails((string) $buyer->name, (string) $buyer->email),
        $buyer,
        null,
        $attendees,
    );
}

function captureOk(Order $order, string $ref = 'cap_ok'): void
{
    app(RecordCapture::class)(
        $order,
        PaymentGateway::Manual,
        CaptureResult::succeeded($ref, $order->total_cents, []),
    );
}

// Order confirmation ----------------------------------------------------------

it('emails the buyer a confirmation when the order is paid', function (): void {
    $buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $order = pendingOrderFor($buyer, [['name' => 'Buyer', 'email' => 'buyer@example.test']]);

    captureOk($order);

    $send = EmailSend::query()->where('email_key', 'purchase.order_confirmation')->sole();

    expect($send->to_email)->toBe('buyer@example.test')
        ->and($send->status)->toBe(EmailStatus::Sent)
        ->and($send->related_id)->toBe($order->getKey());
});

it('does not send a second confirmation when the capture is replayed', function (): void {
    $buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $order = pendingOrderFor($buyer, [['name' => 'Buyer', 'email' => 'buyer@example.test']]);

    captureOk($order, 'cap_same');
    captureOk($order, 'cap_same'); // same gateway id — a replay

    expect(EmailSend::query()->where('email_key', 'purchase.order_confirmation')->count())->toBe(1);
});

// Payment declined ------------------------------------------------------------

it('emails the buyer when the capture is declined', function (): void {
    $buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $order = pendingOrderFor($buyer, [['name' => 'Buyer', 'email' => 'buyer@example.test']]);

    app(RecordCapture::class)(
        $order,
        PaymentGateway::Manual,
        CaptureResult::failed('Card declined', []),
    );

    $confirmation = EmailSend::query()->where('email_key', 'purchase.order_confirmation')->count();
    $declined = EmailSend::query()->where('email_key', 'purchase.payment_declined')->sole();

    expect($confirmation)->toBe(0)
        ->and($declined->to_email)->toBe('buyer@example.test')
        ->and($declined->status)->toBe(EmailStatus::Sent);
});

// Seat assigned ---------------------------------------------------------------

it('emails a colleague their seat but not the buyer', function (): void {
    $buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $order = pendingOrderFor($buyer, [
        ['name' => 'Buyer', 'email' => 'buyer@example.test'],
        ['name' => 'Colleague', 'email' => 'colleague@example.test'],
    ], seats: 2);

    captureOk($order);

    $seatSends = EmailSend::query()->where('email_key', 'purchase.seat_assigned')->get();

    // One seat mail, to the colleague — the buyer already got the confirmation
    // and is not told about their own seat as well.
    expect($seatSends)->toHaveCount(1)
        ->and($seatSends->first()->to_email)->toBe('colleague@example.test');
});

// Invoice PDF -----------------------------------------------------------------

it('rejects an unsigned invoice request', function (): void {
    $order = Order::factory()->paid()->create();

    $this->get('/invoice/'.$order->getKey())->assertForbidden();
});

it('builds the invoice from the frozen billing snapshot', function (): void {
    $buyer = User::factory()->create(['email' => 'buyer@example.test', 'name' => 'Buyer']);
    $order = pendingOrderFor($buyer, [['name' => 'Buyer', 'email' => 'buyer@example.test']], cents: 20000);
    captureOk($order);

    $data = app(GenerateInvoicePdf::class)->data($order->fresh());

    expect($data['billTo']['name'])->toBe('Buyer')
        ->and($data['billTo']['email'])->toBe('buyer@example.test');

    // Render the Blade template itself (no Browsershot — that is verified by eye,
    // §10) and confirm it shows the order and the real total.
    $html = app(GenerateInvoicePdf::class)->view($order->fresh())->render();

    expect($html)->toContain($order->number)
        ->and($html)->toContain(Money::format(20000, 'USD'));
});

<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Entitlements\GrantEntitlementsForOrder;
use App\Email\Dispatcher;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Mail\OrderConfirmation;
use App\Mail\PaymentDeclined;
use App\Mail\SeatAssigned;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\CaptureResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finalises a payment on an order — CLAUDE.md §3's hard gate (P3).
 *
 * This is where money becomes access, and it is deliberately the ONLY place:
 * both the buyer's return and the gateway webhook call it, and it is idempotent
 * so they cannot double-grant. A successful capture writes a `payments` row
 * (its gateway id is the UNIQUE that guards the replay — §14 #5), lets the order
 * status follow the payments rather than any input (§14 #2), and grants exactly
 * the entitlements the order bought. A declined capture writes a failed row and
 * grants nothing.
 */
class RecordCapture
{
    public function __construct(
        private readonly GrantEntitlementsForOrder $grantEntitlements,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function __invoke(Order $order, PaymentGateway $gateway, CaptureResult $result): Payment
    {
        // Whether this call is the one that recorded the payment. A replay finds
        // the row already there, returns early, and leaves this false — so the
        // confirmation (or decline) mail goes out exactly once, with the grant.
        $isNew = false;

        $payment = DB::transaction(function () use ($order, $gateway, $result, &$isNew): Payment {
            // Idempotency: a succeeded capture carries the gateway's unique id.
            // If that payment is already recorded, finalisation happened — return
            // it and do nothing again (no second grant, no second status write).
            if ($result->gatewayPaymentId !== null) {
                $existing = Payment::query()
                    ->where('gateway_payment_id', $result->gatewayPaymentId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $payment = Payment::create([
                'order_id' => $order->getKey(),
                'gateway' => $gateway,
                'gateway_payment_id' => $result->gatewayPaymentId,
                'status' => $result->succeeded ? PaymentStatus::Succeeded : PaymentStatus::Failed,
                // A failed attempt still records what it tried to take, for the
                // log; a success records what the gateway confirmed.
                'amount_cents' => $result->succeeded ? $result->amountCents : $order->total_cents,
                'currency' => $order->currency,
                'raw_response' => $result->raw,
                'processed_at' => $result->succeeded ? now() : null,
            ]);

            // Status always follows the payment rows (§9 / §14 #2), never input.
            // payments.refunds eager-loaded because derivedStatus walks them.
            $order->update(['status' => $order->fresh(['payments.refunds'])?->derivedStatus()]);

            if ($result->succeeded) {
                ($this->grantEntitlements)($order->fresh() ?? $order);
            }

            $isNew = true;

            return $payment;
        });

        // Mail only on the call that finalised it, and only after commit — a
        // receipt must never describe an order that rolled back (§14 #6).
        if ($isNew) {
            $result->succeeded
                ? $this->announcePaid($order->fresh() ?? $order)
                : $this->dispatcher->send(new PaymentDeclined($order));
        }

        return $payment;
    }

    /**
     * Tell the buyer their order is confirmed, and every colleague a seat was
     * booked for — §14 #6 / §15.
     *
     * The seat mail goes only to an attendee whose email differs from the
     * buyer's, so the purchaser is not emailed twice about their own seat, and
     * only to a named seat (a blank row is a seat nobody filled in).
     */
    private function announcePaid(Order $order): void
    {
        $this->dispatcher->send(new OrderConfirmation($order));

        $buyerEmail = Str::lower($order->customerEmail());
        $order->loadMissing(['items.attendees.user', 'items.webinar']);

        foreach ($order->items as $item) {
            foreach ($item->attendees as $attendee) {
                $email = Str::lower((string) $attendee->email);

                if ($email === '' || $email === $buyerEmail) {
                    continue;
                }

                $this->dispatcher->send(new SeatAssigned($attendee));
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refunds a payment through its gateway and keeps the books in step.
 *
 * §4 rule 2 — "Controllers route; Actions do". It lives here rather than in a
 * Filament action because two screens offer it, and a refund written two
 * slightly different ways is how a bank reconciliation ends up disagreeing with
 * the database.
 *
 * The order of operations is the important part:
 *
 *   1. Under a lock, check the ceiling and write a **pending** refund row.
 *   2. Outside the transaction, call the gateway — a network round trip must
 *      not hold a row lock open.
 *   3. Record what it said, and re-derive the order.
 *
 * Writing the row first is what makes step 2 safe. §14 #6 — "never rely on the
 * browser returning": if the request dies mid-call, the pending row survives and
 * says a refund was attempted, rather than the money having gone with nothing to
 * show for it. Its id is also the idempotency key, so a retry reaches the same
 * gateway record instead of refunding twice (§14 #5).
 */
class RecordRefund
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function __invoke(
        Payment $payment,
        int $amountCents,
        string $reason,
        ?int $issuedBy = null,
    ): Refund {
        $driver = $this->gateways->driver($payment->gateway);

        if (! $driver->isConfigured()) {
            throw new RuntimeException(
                $payment->gateway->label().' is not configured, so nothing can be refunded through it yet.'
            );
        }

        $refund = $this->reserve($payment, $amountCents, $reason, $issuedBy);

        $result = $driver->refund(
            $payment,
            $amountCents,
            $reason,
            // Derived from the row, so a retry after a timeout is the same
            // request as far as the gateway is concerned.
            'refund-'.$refund->getKey(),
        );

        $refund->update([
            'status' => $result->succeeded ? RefundStatus::Succeeded : RefundStatus::Failed,
            'gateway_refund_id' => $result->reference,
            'gateway_message' => $result->message,
            'processed_at' => $result->succeeded ? now() : null,
        ]);

        // Only a refund that actually happened moves the order. A failed one
        // leaves it Paid, which is what the money still says.
        if ($result->succeeded) {
            $this->rederiveOrder($payment);
        }

        return $refund->refresh();
    }

    /**
     * Claim the amount before anything is sent.
     *
     * The form checked the ceiling too, but two people refunding the same
     * payment in different tabs would both pass that check — this is where one
     * of them loses.
     */
    private function reserve(Payment $payment, int $amountCents, string $reason, ?int $issuedBy): Refund
    {
        return DB::transaction(function () use ($payment, $amountCents, $reason, $issuedBy): Refund {
            $locked = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load('refunds');

            if ($amountCents < 1) {
                throw new RuntimeException('A refund has to be for something.');
            }

            if ($amountCents > $locked->refundableCents()) {
                throw new RuntimeException('That is more than remains refundable on this payment.');
            }

            return Refund::create([
                'payment_id' => $locked->id,
                'status' => RefundStatus::Pending,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'issued_by' => $issuedBy,
                'processed_at' => null,
            ]);
        });
    }

    /**
     * §9 — the order's status is derived from its payments, so anything that
     * changes one has to re-derive it rather than leave the column asserting
     * something the money no longer supports.
     */
    private function rederiveOrder(Payment $payment): void
    {
        $order = $payment->order;
        $order->load('payments.refunds');

        $order->update(['status' => $order->derivedStatus()]);
    }
}

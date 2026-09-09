<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a refund against a payment and brings the order status with it.
 *
 * §4 rule 2 — "Controllers route; Actions do". It lives here rather than in a
 * Filament action because two screens offer it, the order's payment list and
 * the payment log, and a refund written two slightly different ways is exactly
 * the kind of divergence that shows up in a bank reconciliation.
 *
 * **This does not move money yet.** The gateway drivers are P3, behind its hard
 * gate, and revoking the entitlements a refund should take back needs a ledger
 * that does not exist. What it does is record the decision, cap it at what is
 * actually refundable, and re-derive the order — so the admin never claims more
 * than it has done.
 */
class RecordRefund
{
    public function __invoke(
        Payment $payment,
        int $amountCents,
        string $reason,
        ?int $issuedBy = null,
    ): Refund {
        return DB::transaction(function () use ($payment, $amountCents, $reason, $issuedBy): Refund {
            // Re-read under a lock and re-check the ceiling. The form validated
            // it, but two people refunding the same payment in different tabs
            // would both pass that check and between them return more than was
            // ever taken.
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

            $refund = Refund::create([
                'payment_id' => $locked->id,
                'status' => RefundStatus::Succeeded,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'issued_by' => $issuedBy,
                'processed_at' => now(),
            ]);

            // §9 — the order's status is derived from its payments, so anything
            // that changes one has to re-derive it rather than leave the column
            // asserting something the money no longer supports.
            $order = $locked->order;
            $order->load('payments.refunds');
            $order->update(['status' => $order->derivedStatus()]);

            return $refund;
        });
    }
}

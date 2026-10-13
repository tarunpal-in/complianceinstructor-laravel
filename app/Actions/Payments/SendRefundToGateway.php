<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Entitlements\RevokeEntitlementsForOrder;
use App\Email\Dispatcher;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Mail\RefundIssued;
use App\Models\Refund;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Sends a refund whose cooling-off window has closed.
 *
 * Called by the sweep command rather than by anything a person clicks, so it
 * assumes nothing about who is logged in. It is safe to run twice: the status
 * guard is taken under a lock, so a duplicate sweep finds the row already
 * `pending` and leaves it alone.
 */
class SendRefundToGateway
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly Dispatcher $dispatcher,
        private readonly RevokeEntitlementsForOrder $revokeForOrder,
    ) {}

    public function __invoke(Refund $refund): Refund
    {
        // Claim it before the network call. Marking it pending inside the lock
        // is what stops two overlapping sweeps both refunding.
        $claimed = DB::transaction(function () use ($refund): bool {
            $fresh = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== RefundStatus::Scheduled) {
                return false;
            }

            $fresh->update(['status' => RefundStatus::Pending]);

            return true;
        });

        if (! $claimed) {
            return $refund->refresh();
        }

        $refund->refresh()->load('payment.order');
        $payment = $refund->payment;

        $result = $this->gateways->driver($payment->gateway)->refund(
            $payment,
            $refund->amount_cents,
            (string) $refund->reason,
            // Derived from the row, so a retry after a timeout is the same
            // request as far as the gateway is concerned (§14 #5).
            'refund-'.$refund->getKey(),
        );

        $refund->update([
            'status' => $result->succeeded ? RefundStatus::Succeeded : RefundStatus::Failed,
            'gateway_refund_id' => $result->reference,
            'gateway_message' => $result->message,
            'processed_at' => $result->succeeded ? now() : null,
        ]);

        // Only a refund that actually happened moves the order. A failed one
        // leaves it where the money still says it is.
        if ($result->succeeded) {
            $order = $payment->order;
            $order->load('payments.refunds');
            $status = $order->derivedStatus();
            $order->update(['status' => $status]);

            /*
             * A fully refunded order loses the access it bought — §5.2 / §10.
             * Only on Refunded, not PartiallyRefunded: a partial refund is by
             * amount and cannot say which seat it was for, so the grants stand
             * until an admin takes a named one back by hand. The revoke is keyed
             * on (source Order, this order id), the exact rows the grant wrote.
             */
            if ($status === OrderStatus::Refunded) {
                ($this->revokeForOrder)(
                    $order,
                    'Order '.$order->number.' was refunded.',
                    $refund->issued_by,
                );
            }

            /*
             * And tell the customer, which until now nothing did — only staff
             * heard about a refund. A credit appearing on a card statement with
             * a merchant descriptor and no explanation is a support ticket at
             * best and a chargeback dispute at worst.
             *
             * After the status write, not before: an email saying money is on
             * its way must not go out ahead of the record that says it went.
             */
            $this->dispatcher->send(new RefundIssued($refund->refresh()));
        }

        return $refund->refresh();
    }
}

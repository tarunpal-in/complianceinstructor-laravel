<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Notifications\RefundScheduled;
use App\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Agrees a refund and starts its cooling-off window.
 *
 * Nothing reaches the gateway here. The refund is written as `scheduled`,
 * everyone who could stop it is emailed, and `refunds:release-due` sends it once
 * the window closes — an hour later by default.
 *
 * The delay is not caution for its own sake. Refunding is the one admin action
 * that moves money out irreversibly, and §9's threat model includes an admin
 * account being used by someone else: an hour and an email is the difference
 * between noticing that and reading about it on a bank statement.
 */
class RequestRefund
{
    public const DEFAULT_DELAY_MINUTES = 60;

    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function __invoke(
        Payment $payment,
        int $amountCents,
        string $reason,
        ?int $issuedBy = null,
    ): Refund {
        // Checked now rather than in an hour: telling someone their refund is
        // scheduled and then failing silently overnight is worse than refusing
        // while they are still looking at the screen.
        $driver = $this->gateways->driver($payment->gateway);

        if (! $driver->isConfigured()) {
            throw new RuntimeException(
                $payment->gateway->label().' is not configured, so nothing can be refunded through it yet.'
            );
        }

        $refund = $this->reserve($payment, $amountCents, $reason, $issuedBy);

        // Sent after the row exists, so the mail can never describe a refund
        // that was not written.
        RefundScheduled::notifyApprovers($refund);

        return $refund;
    }

    /**
     * Claim the amount and write the scheduled row.
     *
     * The **order** is locked, not the payment. An order can carry several
     * payments — a card capture and an invoiced top-up, say — and locking one
     * payment does nothing to stop a refund being raised against another at the
     * same moment. The order is the shared parent, so locking it serialises
     * every refund on the order and makes the in-flight check below mean
     * something.
     */
    private function reserve(Payment $payment, int $amountCents, string $reason, ?int $issuedBy): Refund
    {
        return DB::transaction(function () use ($payment, $amountCents, $reason, $issuedBy): Refund {
            Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Payment::query()->whereKey($payment->getKey())->firstOrFail();
            $locked->load('refunds');

            if ($amountCents < 1) {
                throw new RuntimeException('A refund has to be for something.');
            }

            $this->guardAgainstOneInFlight($locked);

            // The form checked this too, but two people refunding the same
            // payment in different tabs would both pass that check — this is
            // where one of them loses.
            if ($amountCents > $locked->refundableCents()) {
                throw new RuntimeException('That is more than remains refundable on this payment.');
            }

            return Refund::create([
                'payment_id' => $locked->id,
                'status' => RefundStatus::Scheduled,
                'scheduled_for' => now()->addMinutes(self::delayMinutes()),
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'issued_by' => $issuedBy,
            ]);
        });
    }

    /**
     * One unresolved refund per order at a time.
     *
     * The per-payment ceiling already stops an order being refunded for more
     * than it took, but it cannot stop two administrators each refunding a
     * different payment of the same order inside the same hour — both are
     * individually valid, and between them the customer gets back twice what
     * either intended. Neither person can see the other's refund from the
     * screen they are on.
     *
     * Blocked rather than warned, because the two ways out are both obvious:
     * cancel the one in flight, or wait for it. Repeat partial refunds over
     * time are unaffected — one seat this week and another next month is normal,
     * and the ceiling keeps that honest on its own.
     */
    private function guardAgainstOneInFlight(Payment $payment): void
    {
        $order = $payment->order;
        $order->load('payments.refunds');

        $inFlight = $order->refundsInFlight();

        if ($inFlight->isEmpty()) {
            return;
        }

        /** @var Refund $first */
        $first = $inFlight->first();

        throw new RuntimeException(sprintf(
            'A refund of %s on order %s has not been sent yet. Cancel it or wait for it before raising another.',
            Money::format($first->amount_cents, $payment->currency),
            $order->number,
        ));
    }

    /**
     * How long the window is.
     *
     * A setting rather than a constant: an hour is the client's answer today,
     * and "make refunds go out immediately" should not need a developer. Zero
     * means the next sweep picks it up, which is within a minute.
     */
    public static function delayMinutes(): int
    {
        $configured = Setting::get('orders.refund_delay_minutes', self::DEFAULT_DELAY_MINUTES);

        return is_numeric($configured) ? max(0, (int) $configured) : self::DEFAULT_DELAY_MINUTES;
    }
}

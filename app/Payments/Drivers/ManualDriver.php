<?php

declare(strict_types=1);

namespace App\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Enums\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\CaptureResult;
use App\Payments\CheckoutIntent;
use App\Payments\RefundResult;
use Illuminate\Support\Str;

/**
 * Invoiced payments, which have no gateway.
 *
 * Money goes back by bank transfer and someone records that it did, so there is
 * nothing to call. It implements the contract anyway so releasing a refund always asks
 * a driver rather than branching on the gateway — the branch is what eventually
 * grows a second, subtly different refund path.
 */
class ManualDriver implements PaymentGatewayDriver
{
    public function gateway(): PaymentGateway
    {
        return PaymentGateway::Manual;
    }

    /** Nothing to configure; recording is always possible. */
    public function isConfigured(): bool
    {
        return true;
    }

    public function refund(
        Payment $payment,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        // No reference: there is no gateway to have issued one, and inventing a
        // value would make a hand-recorded refund look confirmed by someone.
        return RefundResult::succeeded(null);
    }

    /** Invoiced payments are not approved through a gateway — nothing to set up. */
    public function createCheckout(Order $order): CheckoutIntent
    {
        return new CheckoutIntent('manual-'.$order->number);
    }

    /**
     * Recording that an invoiced payment arrived. There is no money to move, so
     * this always affirms the order total; who recorded it is tracked on the
     * payment row, not here.
     */
    public function capture(Order $order, string $reference): CaptureResult
    {
        return CaptureResult::succeeded('manual-'.$order->number.'-'.Str::lower(Str::random(10)), $order->total_cents);
    }
}

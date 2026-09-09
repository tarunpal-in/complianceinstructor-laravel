<?php

declare(strict_types=1);

namespace App\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Payments\RefundResult;

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
}

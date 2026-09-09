<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Payments\RefundResult;

/**
 * CLAUDE.md §8 improvement #1 — one contract, two drivers (three, counting
 * invoiced payments), all writing to the same `payments` and `refunds` tables so
 * reporting and reconciliation never branch by gateway.
 *
 * Refunding is the first operation on it because it is the first one the admin
 * needs. Capturing joins it when checkout lands in P3; the point of the contract
 * is that adding it means one method on three classes rather than a second
 * payment path through the application.
 */
interface PaymentGatewayDriver
{
    public function gateway(): PaymentGateway;

    /**
     * Whether this driver has credentials to work with.
     *
     * Checked before every call rather than assumed: a missing key must produce
     * "PayPal is not configured" rather than an SDK exception halfway through a
     * refund the admin thinks they have issued.
     */
    public function isConfigured(): bool;

    /**
     * Send a refund.
     *
     * `$idempotencyKey` is derived from the local refund row, so a retry after a
     * timeout reaches the same gateway record rather than refunding twice —
     * §14 #5, the constraint whose absence let the predecessor double-grant.
     */
    public function refund(
        Payment $payment,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): RefundResult;
}

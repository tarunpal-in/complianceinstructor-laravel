<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\CaptureResult;
use App\Payments\CheckoutIntent;
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
     * Set up a payment for an order and return what the front end needs to let
     * the buyer approve it — a PayPal order id, a Stripe client secret.
     *
     * The amount comes from the order total, which PlaceOrder computed from the
     * offerings (§14 #1); a gateway is never handed a figure from the request.
     */
    public function createCheckout(Order $order): CheckoutIntent;

    /**
     * Capture the money the buyer approved. `$reference` is the handle the buyer
     * approved against (the PayPal order id, the Stripe PaymentIntent id).
     *
     * A decline is a CaptureResult, not an exception. On success the result
     * carries the gateway's capture/charge id, which becomes the UNIQUE
     * `gateway_payment_id` that stops a replayed return or webhook double-granting
     * (§14 #5 / #6).
     */
    public function capture(Order $order, string $reference): CaptureResult;

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

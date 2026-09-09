<?php

declare(strict_types=1);

namespace App\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Payments\RefundResult;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

/**
 * Refunds through Stripe.
 *
 * `payments.gateway_payment_id` holds the PaymentIntent id (`pi_…`), which is
 * what Cashier records on capture and what Stripe wants here.
 */
class StripeDriver implements PaymentGatewayDriver
{
    public function gateway(): PaymentGateway
    {
        return PaymentGateway::Stripe;
    }

    public function isConfigured(): bool
    {
        return filled($this->secret());
    }

    public function refund(
        Payment $payment,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        if (blank($payment->gateway_payment_id)) {
            return RefundResult::failed('This payment has no Stripe reference to refund against.');
        }

        try {
            $refund = (new StripeClient((string) $this->secret()))->refunds->create(
                [
                    'payment_intent' => $payment->gateway_payment_id,
                    // Cents, which is Stripe's own unit — no conversion, and so
                    // no rounding (§4 rule 6).
                    'amount' => $amountCents,
                    // Stripe's reasons are a closed set and none of them mean
                    // "the client decided to". The admin's words go in metadata
                    // where they survive intact.
                    'metadata' => [
                        'reason' => mb_substr($reason, 0, 500),
                        'order' => (string) $payment->order->number,
                    ],
                ],
                ['idempotency_key' => $idempotencyKey],
            );

            return RefundResult::succeeded($refund->id, $refund->toArray());
        } catch (ApiErrorException $e) {
            // Stripe declining is a normal answer — a disputed charge, or a
            // balance too low to refund from. The admin needs to read it.
            // `->` not `?->`: `??` already swallows a null read, so the
            // nullsafe would be decoration.
            return RefundResult::failed(
                $e->getError()->message ?? $e->getMessage(),
                (array) ($e->getJsonBody() ?? []),
            );
        } catch (Throwable $e) {
            Log::error('Stripe refund failed unexpectedly.', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            return RefundResult::failed('Stripe could not be reached. Nothing was refunded.');
        }
    }

    private function secret(): ?string
    {
        // Through config, never env() at the point of use — config:cache would
        // otherwise blank it in production.
        return config('services.stripe.secret') ?? config('cashier.secret');
    }
}

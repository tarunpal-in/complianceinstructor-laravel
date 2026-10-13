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

    /**
     * A PaymentIntent for the order total, whose client secret the Elements on
     * the checkout page confirm. The amount is the order's own (§14 #1).
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $intent = (new StripeClient((string) $this->secret()))->paymentIntents->create([
            'amount' => $order->total_cents,
            'currency' => strtolower($order->currency),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['order_id' => (string) $order->getKey(), 'order_number' => $order->number],
        ]);

        return new CheckoutIntent((string) $intent->id, null, (string) $intent->client_secret, ['id' => $intent->id]);
    }

    /**
     * Confirm the PaymentIntent the buyer approved client-side. `$reference` is
     * the PaymentIntent id; a status other than `succeeded` is a decline.
     */
    public function capture(Order $order, string $reference): CaptureResult
    {
        try {
            $intent = (new StripeClient((string) $this->secret()))->paymentIntents->retrieve($reference, []);

            if ($intent->status !== 'succeeded') {
                return CaptureResult::failed('The card payment did not complete (status: '.$intent->status.').', ['status' => $intent->status]);
            }

            // The PaymentIntent id is what refunds and reconciliation key on,
            // matching how Cashier records it elsewhere.
            return CaptureResult::succeeded((string) $intent->id, $order->total_cents, ['id' => $intent->id]);
        } catch (Throwable $e) {
            Log::warning('Stripe capture failed.', ['order_id' => $order->id, 'message' => $e->getMessage()]);

            return CaptureResult::failed($e->getMessage());
        }
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

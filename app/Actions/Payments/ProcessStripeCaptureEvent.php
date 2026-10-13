<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\CaptureResult;

/**
 * Finalises an order from a Stripe payment_intent.succeeded event — §14 #6.
 *
 * The Stripe counterpart of ProcessPaypalCaptureEvent, reached through Cashier's
 * already-verified webhook (the HandleStripeWebhook listener). It finds the order
 * by the PaymentIntent's metadata (createCheckout set order_id there) and
 * finalises through the same idempotent RecordCapture, keyed on the PaymentIntent
 * id — the same id the browser-return flow records, so the two cannot grant
 * twice (§14 #5).
 *
 * @see RecordCapture
 */
class ProcessStripeCaptureEvent
{
    public function __construct(private readonly RecordCapture $record) {}

    /**
     * @param  array<string, mixed>  $payload  the Stripe event
     */
    public function __invoke(array $payload): ?Payment
    {
        if (($payload['type'] ?? null) !== 'payment_intent.succeeded') {
            return null;
        }

        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return null;
        }

        $paymentIntentId = (string) ($object['id'] ?? '');
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $orderId = (int) ($metadata['order_id'] ?? 0);

        if ($paymentIntentId === '' || $orderId === 0) {
            return null;
        }

        $order = Order::query()->find($orderId);

        if ($order === null) {
            return null;
        }

        return ($this->record)(
            $order,
            PaymentGateway::Stripe,
            CaptureResult::succeeded($paymentIntentId, $order->total_cents, $object),
        );
    }
}

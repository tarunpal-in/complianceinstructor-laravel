<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\CaptureResult;

/**
 * Finalises an order from a PayPal capture webhook — CLAUDE.md §14 #6.
 *
 * "Never rely on the browser returning." A buyer who pays and closes the tab is
 * finalised here instead, from PayPal's PAYMENT.CAPTURE.COMPLETED event. It goes
 * through the same RecordCapture as the return flow, so the two cannot grant
 * twice: whichever arrives second finds the capture already recorded and no-ops
 * (§14 #5). The order is found by the capture's custom_id, which createCheckout
 * set to the order id.
 *
 * @see RecordCapture
 */
class ProcessPaypalCaptureEvent
{
    public function __construct(private readonly RecordCapture $record) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function __invoke(array $event): ?Payment
    {
        if (($event['event_type'] ?? null) !== 'PAYMENT.CAPTURE.COMPLETED') {
            return null;
        }

        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $captureId = (string) ($resource['id'] ?? '');
        $orderId = (int) ($resource['custom_id'] ?? 0);

        if ($captureId === '' || $orderId === 0) {
            return null;
        }

        $order = Order::query()->find($orderId);

        if ($order === null) {
            return null;
        }

        return ($this->record)(
            $order,
            PaymentGateway::PayPal,
            CaptureResult::succeeded($captureId, $order->total_cents, $resource),
        );
    }
}

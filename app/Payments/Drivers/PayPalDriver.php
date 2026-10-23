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
use App\Support\Money as MoneyHelper;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\ItemRequestBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use RuntimeException;
use Throwable;

/**
 * Refunds through PayPal.
 *
 * PayPal refunds a **capture**, not an order, so `payments.gateway_payment_id`
 * holds the capture id for a PayPal payment. That is the same column Stripe uses
 * for its PaymentIntent — different meanings, one column, because the only thing
 * the application does with it is hand it back to the gateway that issued it.
 */
class PayPalDriver implements PaymentGatewayDriver
{
    public function gateway(): PaymentGateway
    {
        return PaymentGateway::PayPal;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.paypal.client_id'))
            && filled(config('services.paypal.client_secret'));
    }

    /**
     * Create a PayPal order the Buttons can approve, for the order total.
     *
     * The amount is the order's own total (PlaceOrder computed it from the
     * offerings, §14 #1); PayPal is never handed a figure from the request. The
     * order lines are itemised too, so each one shows on the PayPal transaction
     * the way the old WooCommerce site did — see purchaseUnit().
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $response = $this->client()->getOrdersController()->createOrder([
            'body' => OrderRequestBuilder::init(
                CheckoutPaymentIntent::CAPTURE,
                [$this->purchaseUnit($order)],
            )->build(),
            'prefer' => 'return=representation',
        ]);

        $result = $response->getResult();
        $raw = $this->toArray($result);
        $id = is_object($result) && method_exists($result, 'getId') ? (string) $result->getId() : '';

        if ($id === '') {
            throw new RuntimeException('PayPal did not return an order id.');
        }

        return new CheckoutIntent($id, $this->approveLink($raw), null, $raw);
    }

    /**
     * Capture the order the buyer approved. `$reference` is the PayPal order id.
     *
     * The captured amount recorded is the order total, not PayPal's echoed
     * figure — the application only trusts the number it computed. A capture that
     * does not come back COMPLETED is a decline, surfaced as a failed result.
     */
    public function capture(Order $order, string $reference): CaptureResult
    {
        try {
            $response = $this->client()->getOrdersController()->captureOrder([
                'id' => $reference,
                'prefer' => 'return=representation',
            ]);

            $raw = $this->toArray($response->getResult());
            $capture = $raw['purchase_units'][0]['payments']['captures'][0] ?? null;

            if (! is_array($capture) || strtoupper((string) ($capture['status'] ?? '')) !== 'COMPLETED') {
                return CaptureResult::failed('PayPal did not complete the capture.', $raw);
            }

            return CaptureResult::succeeded((string) ($capture['id'] ?? $reference), $order->total_cents, $raw);
        } catch (Throwable $e) {
            Log::warning('PayPal capture failed.', ['order_id' => $order->id, 'message' => $e->getMessage()]);

            return CaptureResult::failed($this->readableMessage($e));
        }
    }

    public function refund(
        Payment $payment,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        if (blank($payment->gateway_payment_id)) {
            return RefundResult::failed('This payment has no PayPal capture to refund against.');
        }

        try {
            $response = $this->client()->getPaymentsController()->refundCapturedPayment([
                'captureId' => $payment->gateway_payment_id,
                // PayPal's idempotency header. A retry after a timeout reaches
                // the same refund rather than issuing a second one.
                'paypalRequestId' => $idempotencyKey,
                'prefer' => 'return=representation',
                'body' => RefundRequestBuilder::init()
                    ->amount(
                        MoneyBuilder::init(
                            strtoupper($payment->currency),
                            // PayPal wants a decimal string, so this is the one
                            // place cents become one — through the shared
                            // helper, never a float multiplication.
                            MoneyHelper::toDecimal($amountCents),
                        )->build(),
                    )
                    ->noteToPayer(mb_substr($reason, 0, 255))
                    ->build(),
            ]);

            $result = $response->getResult();
            $reference = is_object($result) && method_exists($result, 'getId') ? $result->getId() : null;

            return RefundResult::succeeded($reference, $this->toArray($result));
        } catch (Throwable $e) {
            // The SDK throws for declines as well as transport faults, so the
            // message is surfaced rather than swallowed — the admin has to know
            // whether the money moved.
            Log::warning('PayPal refund failed.', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            return RefundResult::failed($this->readableMessage($e));
        }
    }

    /**
     * The purchase unit for the order — itemised when the figures reconcile.
     *
     * PayPal shows `items[]` on the transaction (the WooCommerce parity the
     * client asked for), but it rejects the whole order if the breakdown does
     * not add up to the amount. So the lines are only sent when
     * `item_total − discount + tax` equals the order's own total to the cent;
     * otherwise this falls back to the plain total, so a stray rounding never
     * blocks a payment. Either way the amount handed to PayPal is the order's
     * computed total, never a recomputed one (§14 #1).
     */
    private function purchaseUnit(Order $order): \PaypalServerSdkLib\Models\PurchaseUnitRequest
    {
        $order->loadMissing('items');
        $currency = strtoupper($order->currency);

        $items = [];
        $itemTotal = 0;

        foreach ($order->items as $item) {
            $quantity = max(1, (int) $item->quantity);
            $unitCents = (int) $item->unit_price_cents;
            $itemTotal += $unitCents * $quantity;

            $items[] = ItemRequestBuilder::init(
                $this->itemName($item),
                MoneyBuilder::init($currency, MoneyHelper::toDecimal($unitCents))->build(),
                (string) $quantity,
            )->sku($this->itemSku($item))->build();
        }

        $discount = (int) $order->discount_cents;
        $tax = (int) $order->tax_cents;
        $itemised = $items !== [] && ($itemTotal - $discount + $tax) === (int) $order->total_cents;

        $amount = AmountWithBreakdownBuilder::init($currency, MoneyHelper::toDecimal($order->total_cents));

        if ($itemised) {
            $breakdown = AmountBreakdownBuilder::init()
                ->itemTotal(MoneyBuilder::init($currency, MoneyHelper::toDecimal($itemTotal))->build());

            if ($tax > 0) {
                $breakdown = $breakdown->taxTotal(MoneyBuilder::init($currency, MoneyHelper::toDecimal($tax))->build());
            }

            if ($discount > 0) {
                $breakdown = $breakdown->discount(MoneyBuilder::init($currency, MoneyHelper::toDecimal($discount))->build());
            }

            $amount = $amount->breakdown($breakdown->build());
        }

        $unit = PurchaseUnitRequestBuilder::init($amount->build())
            ->customId((string) $order->getKey())
            ->description(mb_substr('Order '.$order->number, 0, 127));

        if ($itemised) {
            $unit = $unit->items($items);
        }

        return $unit->build();
    }

    /** The line's name for PayPal — its description, capped at PayPal's 127. */
    private function itemName(\App\Models\OrderItem $item): string
    {
        $name = trim((string) $item->description);

        return mb_substr($name === '' ? 'Webinar access' : $name, 0, 127);
    }

    private function itemSku(\App\Models\OrderItem $item): ?string
    {
        $sku = trim((string) $item->sku);

        return $sku === '' ? null : mb_substr($sku, 0, 127);
    }

    private function client(): \PaypalServerSdkLib\PaypalServerSdkClient
    {
        return PaypalServerSdkClientBuilder::init()
            // The credentials builder is handed over as-is; unlike the request
            // builders it has no build() of its own.
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init(
                    (string) config('services.paypal.client_id'),
                    (string) config('services.paypal.client_secret'),
                ),
            )
            ->environment(
                config('services.paypal.mode') === 'live'
                    ? Environment::PRODUCTION
                    : Environment::SANDBOX,
            )
            ->build();
    }

    /**
     * The URL PayPal wants the buyer sent to, for the redirect flow. The Buttons
     * flow uses the order id directly and ignores this.
     *
     * @param  array<string, mixed>  $raw
     */
    private function approveLink(array $raw): ?string
    {
        foreach (is_array($raw['links'] ?? null) ? $raw['links'] : [] as $link) {
            if (is_array($link) && in_array($link['rel'] ?? null, ['approve', 'payer-action'], true)) {
                return is_string($link['href'] ?? null) ? $link['href'] : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function toArray(mixed $result): array
    {
        if (! is_object($result)) {
            return [];
        }

        $encoded = json_decode((string) json_encode($result), true);

        return is_array($encoded) ? $encoded : [];
    }

    private function readableMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === ''
            ? 'PayPal refused the refund without giving a reason.'
            : $message;
    }
}

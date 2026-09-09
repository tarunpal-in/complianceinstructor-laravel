<?php

declare(strict_types=1);

namespace App\Payments\Drivers;

use App\Contracts\PaymentGatewayDriver;
use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Payments\RefundResult;
use App\Support\Money as MoneyHelper;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
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

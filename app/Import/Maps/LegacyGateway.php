<?php

declare(strict_types=1);

namespace App\Import\Maps;

use App\Enums\PaymentGateway;

/**
 * WooCommerce payment-method slug → the new `PaymentGateway`.
 *
 * The live store took money through PayPal (legacy Express Checkout `ppec_paypal`
 * and the newer Commerce Platform `ppcp-*`), Stripe, and a handful of Razorpay.
 * The new model has two live drivers plus `manual`; Razorpay and any offline
 * method have no driver, so they import as `manual` — a faithful record of money
 * taken, without claiming a gateway that can refund it here. Historic payments
 * are records, never re-charged, so this only needs to classify them.
 */
final class LegacyGateway
{
    public static function toEnum(string $method): PaymentGateway
    {
        $method = strtolower(trim($method));

        return match (true) {
            str_contains($method, 'stripe') => PaymentGateway::Stripe,
            str_contains($method, 'paypal'), str_contains($method, 'ppcp'), str_contains($method, 'ppec') => PaymentGateway::PayPal,
            // razorpay, bacs, cheque, cod, '' and anything unrecognised.
            default => PaymentGateway::Manual,
        };
    }
}

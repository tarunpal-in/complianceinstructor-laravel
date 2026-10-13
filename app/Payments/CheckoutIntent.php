<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * What the front end needs to let a buyer pay — the gateway's handle on a
 * payment it has set up for an order (CLAUDE.md §8).
 *
 * One shape for two gateways: PayPal returns an order id its Buttons approve;
 * Stripe returns a PaymentIntent client secret its Elements confirm. The capture
 * step then finalises whichever one the buyer approved.
 */
readonly class CheckoutIntent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gatewayOrderId,
        public ?string $approveUrl = null,
        public ?string $clientSecret = null,
        public array $raw = [],
    ) {}
}

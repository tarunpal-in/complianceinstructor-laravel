<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * What a gateway said when asked to capture a payment — the capture-side sibling
 * of RefundResult (CLAUDE.md §8).
 *
 * A decline is an outcome, not an exception: a card refused or an approval that
 * lapsed is something the gateway is entitled to say, and the buyer needs to be
 * told. Only a configuration or programming fault throws. On success the gateway
 * id (`gateway_payment_id`) is the idempotency key — the UNIQUE that stops a
 * replayed return or webhook double-granting (§14 #5).
 */
readonly class CaptureResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public bool $succeeded,
        public ?string $gatewayPaymentId,
        public int $amountCents,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function succeeded(string $gatewayPaymentId, int $amountCents, array $raw = []): self
    {
        return new self(true, $gatewayPaymentId, $amountCents, null, $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function failed(string $message, array $raw = []): self
    {
        return new self(false, null, 0, $message, $raw);
    }
}

<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * What a gateway said when asked to refund.
 *
 * A decline is an outcome, not an exception: the card network refusing, or the
 * balance being short, is something the gateway is entitled to say and the admin
 * needs to read. Only a programming or configuration fault throws.
 */
readonly class RefundResult
{
    /**
     * @param  string|null  $reference  The gateway's own refund id, for reconciliation.
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public bool $succeeded,
        public ?string $reference = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function succeeded(?string $reference, array $raw = []): self
    {
        return new self(true, $reference, null, $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function failed(string $message, array $raw = []): self
    {
        return new self(false, null, $message, $raw);
    }
}

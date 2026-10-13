<?php

declare(strict_types=1);

namespace App\Checkout;

/**
 * The buyer's details, frozen onto the order as `billing_snapshot` (§5.4).
 *
 * A snapshot, not a live reference: §5.4 is firm that a historic invoice must
 * never change because the customer later edited their profile. Captured at
 * checkout and stored as-is.
 */
final readonly class BillingDetails
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $company = null,
        public ?string $country = null,
    ) {}

    /** @return array<string, string> */
    public function toSnapshot(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'company' => (string) $this->company,
            'country' => (string) $this->country,
        ], fn (string $value): bool => $value !== '');
    }
}

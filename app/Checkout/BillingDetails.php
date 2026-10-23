<?php

declare(strict_types=1);

namespace App\Checkout;

/**
 * The buyer's details, frozen onto the order as `billing_snapshot` (§5.4).
 *
 * A snapshot, not a live reference: §5.4 is firm that a historic invoice must
 * never change because the customer later edited their profile. Captured at
 * checkout and stored as-is.
 *
 * `company` and `country` keep their original positions so the existing
 * positional callers (and the money-path tests) are untouched; the fuller
 * billing address, the required job title and the phone are appended. "Required"
 * is a form concern enforced at checkout — this DTO stays permissive so a
 * snapshot can be rebuilt from whatever an order actually froze.
 */
final readonly class BillingDetails
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $company = null,
        public ?string $country = null,
        public ?string $jobTitle = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $phone = null,
    ) {}

    /** @return array<string, string> */
    public function toSnapshot(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => (string) $this->phone,
            'job_title' => (string) $this->jobTitle,
            'company' => (string) $this->company,
            'address_line1' => (string) $this->addressLine1,
            'address_line2' => (string) $this->addressLine2,
            'city' => (string) $this->city,
            'region' => (string) $this->region,
            'postal_code' => (string) $this->postalCode,
            'country' => (string) $this->country,
        ], fn (string $value): bool => $value !== '');
    }
}

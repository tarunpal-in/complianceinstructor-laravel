<?php

declare(strict_types=1);

namespace App\Coupons;

/**
 * Whether a coupon may be used, and what it is worth.
 *
 * A refusal carries a reason the customer can read. It deliberately does not
 * carry *which* rule refused in machine-readable form: telling someone a code
 * exists but they have used it too often, versus that it does not exist, is a
 * difference worth keeping vague at the checkout and precise in the admin.
 */
readonly class CouponVerdict
{
    private function __construct(
        public bool $accepted,
        public int $discountCents = 0,
        public ?string $reason = null,
    ) {}

    public static function accepted(int $discountCents): self
    {
        return new self(true, $discountCents);
    }

    public static function refused(string $reason): self
    {
        return new self(false, 0, $reason);
    }
}

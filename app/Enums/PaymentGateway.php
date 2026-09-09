<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * CLAUDE.md §8 improvement #1 — one `PaymentGateway` contract, two drivers,
 * both writing to the same `payments` table so reporting never branches.
 *
 * `manual` is the third: this audience is B2B and frequently invoiced rather
 * than paying by card (§1). A manual payment is recorded by someone in Finance
 * against a bank transfer or cheque, which is why it is gated behind its own
 * `manage_manual_payment` permission rather than ordinary order editing.
 */
enum PaymentGateway: string
{
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::PayPal => 'PayPal',
            self::Manual => 'Manual / invoice',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Stripe => 'info',
            self::PayPal => 'warning',
            self::Manual => 'gray',
        };
    }

    /**
     * Whether a human recorded this rather than a gateway confirming it.
     *
     * Manual payments have no webhook to verify them, so they are the one path
     * where the audit trail is the only evidence — hence the activity log entry
     * and the separate permission.
     */
    public function isManual(): bool
    {
        return $this === self::Manual;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

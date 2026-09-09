<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * CLAUDE.md §5.4.
 *
 * Derived from the order's `payments` rows, never set from request input.
 * §14 #2 — the predecessor completed any cart for free by posting
 * `status=place_order`, so nothing outside the payment path may move an order
 * into a paid state.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'warning',
            self::PartiallyRefunded => 'info',
            self::Refunded => 'gray',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    /**
     * Whether money has actually been taken.
     *
     * This is the question entitlements hang off — a pending order grants
     * nothing, and a fully refunded one has had its grants revoked.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /** Statuses that still count towards revenue. */
    public function countsAsRevenue(): bool
    {
        return $this->isSettled();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
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

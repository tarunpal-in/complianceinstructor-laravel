<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * CLAUDE.md §5.4. A refund is its own record against a payment rather than a
 * mutation of it, so the original amount taken stays readable forever.
 *
 * The lifecycle has a deliberate pause in it. A refund is `scheduled` first and
 * only reaches the gateway once its cooling-off window has passed, so a mistake
 * — or an account someone else is using — can be caught before money leaves.
 *
 *   scheduled ──▶ pending ──▶ succeeded
 *        │            └─────▶ failed
 *        └──▶ cancelled
 */
enum RefundStatus: string
{
    /** Agreed and waiting out its window. Nothing has been sent. */
    case Scheduled = 'scheduled';

    /** Handed to the gateway; waiting on an answer. */
    case Pending = 'pending';

    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Stopped during the window. No money moved. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Pending => 'Sending',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Scheduled => 'info',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Whether this refund is holding money that cannot be refunded again.
     *
     * Everything except a failure and a cancellation: those released their
     * claim, so the amount is available to try once more.
     */
    public function holdsFunds(): bool
    {
        return in_array($this, [self::Scheduled, self::Pending, self::Succeeded], true);
    }

    /** Only a refund that has not been sent can be stopped. */
    public function isStoppable(): bool
    {
        return $this === self::Scheduled;
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

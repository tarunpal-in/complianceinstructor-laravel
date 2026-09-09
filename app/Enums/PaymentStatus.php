<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one attempt to take money — CLAUDE.md §5.4.
 *
 * An order's status is derived from these rows (§14 #2), so this is the record
 * that actually decides whether anything was fulfilled.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
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

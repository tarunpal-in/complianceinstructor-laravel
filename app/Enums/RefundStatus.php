<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * CLAUDE.md §5.4. A refund is its own record against a payment rather than a
 * mutation of it, so the original amount taken stays readable forever.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
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

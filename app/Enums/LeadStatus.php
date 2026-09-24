<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far somebody has got with a lead.
 *
 * A human judgement, which is why it is stored. Note what is *not* here:
 * **Converted**. Whether a lead became a customer is answered by looking for an
 * account on their email with a settled order against it — derived, the way
 * coupon redemptions and membership allowances are counted rather than kept in
 * a column. A `converted` status is a number somebody has to remember to
 * change, and the first time they forget, §8's funnel report is wrong and
 * nobody notices.
 *
 * Four values on purpose. A pipeline with nine stages is a CRM, and this is a
 * register of people who asked to be contacted.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Working = 'working';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Working => 'Being worked',
            self::Qualified => 'Qualified',
            self::Unqualified => 'Not a fit',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Working => 'info',
            self::Qualified => 'success',
            self::Unqualified => 'gray',
        };
    }

    /** Whether somebody still has to do something about it. */
    public function isOpen(): bool
    {
        return $this === self::New || $this === self::Working;
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

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an inquiry has got to.
 *
 * Four values, phrased around the one thing that matters: has this person had a
 * reply. "Qualified" and "not a fit" are sales words, and most of what arrives
 * through a contact form is a question — somebody asking whether the FMLA
 * webinar carries HRCI credit, or whether there is a rate for twelve seats.
 * Grading those on a sales pipeline would mean staff picking a stage that does
 * not describe what they did.
 *
 * Note what is *not* here: **Converted**. Whether somebody went on to buy is
 * answered by looking for an account on their email with a settled order
 * against it — derived, the way coupon redemptions and membership allowances
 * are counted rather than kept in a column. A stored flag is a thing somebody
 * has to remember to set, and the first time they forget, §8's funnel report is
 * wrong and nobody notices.
 */
enum InquiryStatus: string
{
    /** Arrived. Nobody has looked at it. */
    case New = 'new';

    /** Somebody has taken it on, and has not replied yet. */
    case Working = 'working';

    /** They have had their answer. */
    case Answered = 'answered';

    /** Finished with, without a reply being owed — spam, a duplicate, a mistake. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Working => 'Being handled',
            self::Answered => 'Answered',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Working => 'info',
            self::Answered => 'success',
            self::Closed => 'gray',
        };
    }

    /** Whether somebody still owes this person something. */
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

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why somebody has access — CLAUDE.md §5.2.
 *
 * The three routes to content converge here: buy a webinar format, buy a
 * package containing it, or spend a membership allowance. The predecessor
 * answered "can this person watch this?" by joining `user_purchases`, then
 * date-checking `purchase_plans`, then reading seventeen boolean columns on
 * `memberships` — duplicated across four dashboard controllers, with an
 * inconsistent `is_package` filter in one of them, so package buyers could not
 * see their own recordings.
 *
 * `manual` is the one §5.2 adds for people rather than for code: a comp, a
 * goodwill replacement, a correction. It is deliberately a source of its own so
 * that support giving somebody a recording never looks like a purchase and
 * never comes out of a quota.
 */
enum EntitlementSource: string
{
    case Order = 'order';
    case Subscription = 'subscription';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Purchase',
            self::Subscription => 'Membership',
            self::Manual => 'Granted by staff',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Order => 'success',
            self::Subscription => 'info',
            self::Manual => 'warning',
        };
    }

    /**
     * Whether a grant from this source spends a membership allowance.
     *
     * Only a membership does. A purchase was paid for separately, and a manual
     * grant is a gift — charging either against the quota would mean support
     * solving one complaint by creating another.
     */
    public function consumesAllowance(): bool
    {
        return $this === self::Subscription;
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

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a membership renews — CLAUDE.md §5.5.
 *
 * `one_time` is not a renewal at all: it buys a fixed span of access, which is
 * what `duration_months` measures. The two fields have to agree, and
 * MembershipPlan::problems() is where that is enforced — a plan that renews
 * monthly *and* claims a twelve-month duration is telling the customer two
 * different things.
 */
enum BillingPeriod: string
{
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Quarter => 'Quarterly',
            self::Year => 'Yearly',
            self::OneTime => 'One-off payment',
        };
    }

    /** How the price reads on a pricing card — "$99 / month". */
    public function suffix(): string
    {
        return match ($this) {
            self::Month => '/ month',
            self::Quarter => '/ quarter',
            self::Year => '/ year',
            self::OneTime => 'one-off',
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    /**
     * How long one billing term lasts, in months.
     *
     * Null for a one-off, where the span is whatever `duration_months` says
     * rather than anything the period implies.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Month => 1,
            self::Quarter => 3,
            self::Year => 12,
            self::OneTime => null,
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

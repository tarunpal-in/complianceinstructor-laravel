<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a subscription stands — CLAUDE.md §4 rule 5, "enums for every status …
 * subscription state".
 *
 * Derived, never stored. Cashier owns `stripe_status` and a second column
 * saying the same thing in our words would eventually disagree with it, which
 * is the whole reason §5.5's own `subscriptions.status` was dropped in favour
 * of Cashier's table.
 *
 * Most cases are Stripe's own status values so the mapping is direct. Two are
 * not, and they are the two the client actually asks about:
 *
 *  - `Ending` is Stripe's `active` plus an `ends_at` in the future: cancelled,
 *    but paid up until then. Showing that as "active" hides a member who is
 *    leaving; showing it as "cancelled" hides that they still have access.
 *  - `Unknown` is any status Stripe adds after this was written. Saying so is
 *    better than quietly filing it under something plausible.
 */
enum SubscriptionState: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Ending = 'ending';
    case Ended = 'canceled';
    case Incomplete = 'incomplete';
    case NeverStarted = 'incomplete_expired';
    case Unpaid = 'unpaid';
    case Paused = 'paused';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'On trial',
            self::Active => 'Active',
            self::PastDue => 'Payment failed',
            self::Ending => 'Ending',
            self::Ended => 'Ended',
            self::Incomplete => 'Awaiting first payment',
            self::NeverStarted => 'Never started',
            self::Unpaid => 'Unpaid',
            self::Paused => 'Paused',
            self::Unknown => 'Unknown',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Active, self::Trialing => 'success',
            self::Ending => 'warning',
            self::PastDue, self::Unpaid => 'danger',
            self::Ended, self::NeverStarted, self::Paused, self::Incomplete, self::Unknown => 'gray',
        };
    }

    /**
     * Whether the member can still use what they bought.
     *
     * Not the same as "paying". A member in their grace period has cancelled
     * and still has access; one whose payment failed is still being charged and
     * may not. P4's entitlement grants read this, which is why it is one
     * definition rather than a condition written out at each call site — the
     * predecessor lost a whole quota check to exactly that.
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Active, self::Trialing, self::Ending], true);
    }

    /** Whether somebody needs to do something about it. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::PastDue, self::Unpaid, self::Incomplete], true);
    }

    /** What a Stripe status means here, with anything unrecognised said plainly. */
    public static function fromStripeStatus(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Unknown;
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

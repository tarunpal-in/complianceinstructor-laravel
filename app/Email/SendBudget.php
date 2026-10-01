<?php

declare(strict_types=1);

namespace App\Email;

use App\Enums\EmailStatus;
use App\Models\EmailSend;
use App\Models\Setting;

/**
 * How much of today's sending allowance is left.
 *
 * A consumer Gmail account allows roughly 500 messages a day, and **exceeding it
 * blocks the account for about 24 hours** — password resets and receipts
 * included, because they share the mailbox. That is the failure this class
 * exists to prevent, and it is not a theoretical one: a single 400-seat
 * webinar's one-hour reminder is most of a day's allowance in a few minutes.
 *
 * **Two caps, not one.** The bulk ceiling sits below the daily ceiling, so
 * reminders and marketing can never consume the headroom that a password reset
 * needs. Somebody locked out of their account at 4pm because a newsletter went
 * out at 3pm is the specific outcome being bought off here.
 *
 * The window is rolling 24 hours rather than a calendar day, because Google's is.
 */
final class SendBudget
{
    /** Sends that counted against the allowance in the last 24 hours. */
    public static function used(): int
    {
        return EmailSend::query()
            ->where('status', EmailStatus::Sent)
            ->where('sent_at', '>=', now()->subDay())
            ->count();
    }

    public static function dailyCap(): int
    {
        return self::cap('email.daily_cap', 450);
    }

    /**
     * The ceiling for bulk mail, below the daily one.
     *
     * The gap between the two is the reserve. Deliberately expressed as its own
     * number rather than a percentage: "always keep 100 in hand" is a sentence
     * the client can check, and a percentage is one they would have to compute.
     */
    public static function bulkCap(): int
    {
        return min(self::cap('email.bulk_cap', 300), self::dailyCap());
    }

    public static function perMinuteCap(): int
    {
        return self::cap('email.per_minute_cap', 15);
    }

    /** What a bulk send has left before it must wait. */
    public static function remainingForBulk(): int
    {
        return max(0, self::bulkCap() - self::used());
    }

    public static function remainingForTransactional(): int
    {
        return max(0, self::dailyCap() - self::used());
    }

    public static function remainingFor(EmailDefinition $email): int
    {
        return $email->isBulk()
            ? self::remainingForBulk()
            : self::remainingForTransactional();
    }

    public static function allows(EmailDefinition $email): bool
    {
        return self::remainingFor($email) > 0;
    }

    /** Why a send is waiting, in words a person can act on. */
    public static function exhaustedReason(EmailDefinition $email): string
    {
        $cap = $email->isBulk() ? self::bulkCap() : self::dailyCap();
        $kind = $email->isBulk() ? 'bulk' : 'transactional';

        return "The 24-hour {$kind} allowance of {$cap} is spent (".self::used().' sent). '
            .'This will go out as the allowance frees up.';
    }

    /** Whether the screen should be warning somebody. */
    public static function isNearLimit(): bool
    {
        $cap = self::dailyCap();

        return $cap > 0 && self::used() >= (int) floor($cap * 0.8);
    }

    public static function percentUsed(): int
    {
        $cap = self::dailyCap();

        return $cap > 0 ? min(100, (int) round(self::used() / $cap * 100)) : 0;
    }

    public static function deferredCount(): int
    {
        return EmailSend::query()->where('status', EmailStatus::Deferred)->count();
    }

    private static function cap(string $key, int $fallback): int
    {
        $value = Setting::declared($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}

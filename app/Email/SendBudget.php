<?php

declare(strict_types=1);

namespace App\Email;

use App\Enums\EmailStatus;
use App\Models\EmailSend;
use App\Models\Setting;

/**
 * How much of today's sending allowance is left.
 *
 * **The whole allowance mechanism is a consumer-Gmail concern.** A free Gmail
 * account allows roughly 500 messages a day, and exceeding it blocks the account
 * for about 24 hours — password resets and receipts included, because they share
 * the mailbox. A real SMTP provider — SES, Postmark, the client's own server —
 * has no such per-account cliff, and managing an allowance it does not have would
 * be inventing a limit and then defending it.
 *
 * So the caps apply only when they are real. `capped()` is the one gate: when it
 * is false, nothing defers, the allowance panel disappears, and the per-minute
 * throttle lifts. The default is automatic — on for a Gmail host, off for any
 * other SMTP — with `email.sending_limit` as the override for the two cases
 * detection cannot see: a non-Gmail provider that *does* impose a limit (SES in
 * sandbox), and a reason to turn the Gmail guard off deliberately.
 */
final class SendBudget
{
    /**
     * Whether a per-account daily allowance applies at all.
     *
     * The single source of truth. Everything below either returns "unlimited"
     * or lifts entirely when this is false.
     */
    public static function capped(): bool
    {
        return match (self::mode()) {
            'always' => true,
            'never' => false,
            // 'auto'
            default => self::usesConsumerGmail(),
        };
    }

    /**
     * Whether mail is going out through a consumer Gmail account.
     *
     * Host-based, because that is the only signal there is: a Workspace account
     * and a free one both send through smtp.gmail.com. Both have *a* cap and the
     * conservative default (450) is safe for either, so not telling them apart
     * is the right call. Any other host — or a non-SMTP mailer — is treated as a
     * provider without a per-account cliff.
     */
    public static function usesConsumerGmail(): bool
    {
        if ((string) config('mail.default') !== 'smtp') {
            return false;
        }

        $host = mb_strtolower((string) config('mail.mailers.smtp.host'));

        return str_contains($host, 'gmail.com') || str_contains($host, 'googlemail.com');
    }

    /** The configured SMTP host, for the screen to name what it is sending through. */
    public static function host(): ?string
    {
        if ((string) config('mail.default') !== 'smtp') {
            return (string) config('mail.default');
        }

        $host = (string) config('mail.mailers.smtp.host');

        return $host === '' ? null : $host;
    }

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

    /** Whether the SendEmail job should throttle. Lifts with the caps. */
    public static function throttlesPerMinute(): bool
    {
        return self::capped();
    }

    /** What a bulk send has left before it must wait. Unlimited when uncapped. */
    public static function remainingForBulk(): int
    {
        return self::capped() ? max(0, self::bulkCap() - self::used()) : PHP_INT_MAX;
    }

    public static function remainingForTransactional(): int
    {
        return self::capped() ? max(0, self::dailyCap() - self::used()) : PHP_INT_MAX;
    }

    public static function remainingFor(EmailDefinition $email): int
    {
        return $email->isBulk()
            ? self::remainingForBulk()
            : self::remainingForTransactional();
    }

    /**
     * Whether this email may go out now, rather than waiting.
     *
     * Always true when uncapped — a real provider has no allowance to spend, so
     * nothing it sends is ever deferred.
     */
    public static function allows(EmailDefinition $email): bool
    {
        return ! self::capped() || self::remainingFor($email) > 0;
    }

    /** Why a send is waiting, in words a person can act on. */
    public static function exhaustedReason(EmailDefinition $email): string
    {
        $cap = $email->isBulk() ? self::bulkCap() : self::dailyCap();
        $kind = $email->isBulk() ? 'bulk' : 'transactional';

        return "The 24-hour {$kind} allowance of {$cap} is spent (".self::used().' sent). '
            .'This will go out as the allowance frees up.';
    }

    /** Whether the screen should be warning somebody. Never, when uncapped. */
    public static function isNearLimit(): bool
    {
        if (! self::capped()) {
            return false;
        }

        $cap = self::dailyCap();

        return $cap > 0 && self::used() >= (int) floor($cap * 0.8);
    }

    public static function percentUsed(): int
    {
        if (! self::capped()) {
            return 0;
        }

        $cap = self::dailyCap();

        return $cap > 0 ? min(100, (int) round(self::used() / $cap * 100)) : 0;
    }

    public static function deferredCount(): int
    {
        return EmailSend::query()->where('status', EmailStatus::Deferred)->count();
    }

    /**
     * auto | always | never, defaulting to auto for anything unexpected.
     *
     * Through declared() so a typo in the stored value falls back to the registry
     * default rather than silently disabling the Gmail guard.
     */
    private static function mode(): string
    {
        $mode = Setting::declared('email.sending_limit');

        return in_array($mode, ['auto', 'always', 'never'], true) ? (string) $mode : 'auto';
    }

    private static function cap(string $key, int $fallback): int
    {
        $value = Setting::declared($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}

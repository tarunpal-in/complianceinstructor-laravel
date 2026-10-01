<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of email this is — which decides three separate things.
 *
 * 1. **Whether a recipient may turn it off.** Nobody wants a 34-row preference
 *    screen, so preferences are offered per category. Receipts are not a
 *    category, because "stop sending me the receipt for the thing I bought" is
 *    not a preference anybody is owed.
 * 2. **Which queue it uses.** Transactional mail must never queue behind a
 *    400-recipient reminder blast, because on a consumer Gmail account that
 *    blast is most of the daily allowance.
 * 3. **How long the rendered body is kept.** A disputed invoice surfaces a year
 *    later; nobody disputes a reminder after a quarter.
 */
enum EmailCategory: string
{
    /** Signing in, passwords, two-factor. Never optional. */
    case Account = 'account';

    /** Money: confirmations, receipts, refunds. Never optional. */
    case Purchase = 'purchase';

    /** Credits and certificates — the thing customers are actually buying. */
    case Credential = 'credential';

    /** Reminders and join links for a session. */
    case Reminder = 'reminder';

    /** A recording, transcript or download becoming available. */
    case Delivery = 'delivery';

    /** Plan lifecycle: welcome, renewal, dunning, cancellation. */
    case Membership = 'membership';

    /** Marketing. Needs an unsubscribe link by law. */
    case Marketing = 'marketing';

    /** To staff, about the business. Not subject to recipient preferences. */
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account & security',
            self::Purchase => 'Orders & receipts',
            self::Credential => 'Certificates',
            self::Reminder => 'Session reminders',
            self::Delivery => 'Recordings & transcripts',
            self::Membership => 'Membership notices',
            self::Marketing => 'Marketing',
            self::Internal => 'Staff notifications',
        };
    }

    /**
     * Whether a recipient may switch this category off for themselves.
     *
     * The four that cannot are the four they are owed: how to get into their
     * account, what they were charged, the credits they bought, and — for staff
     * mail — a notification that is part of somebody's job.
     */
    public function mayBeDeclinedByRecipient(): bool
    {
        return match ($this) {
            self::Account, self::Purchase, self::Credential, self::Internal => false,
            self::Reminder, self::Delivery, self::Membership, self::Marketing => true,
        };
    }

    /**
     * Whether the law expects an unsubscribe link in the footer.
     *
     * Marketing only. Putting one on a receipt invites somebody to opt out of
     * their own invoices, and then to ask where their invoice went.
     */
    public function needsUnsubscribeLink(): bool
    {
        return $this === self::Marketing;
    }

    /**
     * Which queue.
     *
     * Reminders, deliveries and marketing can go out in hundreds at once;
     * everything else is one person waiting for one email, usually while looking
     * at a screen that says it has been sent.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Reminder, self::Delivery, self::Marketing => 'email-bulk',
            default => 'email-transactional',
        };
    }

    public function isBulk(): bool
    {
        return $this->queue() === 'email-bulk';
    }

    /**
     * The setting key holding how long to keep rendered bodies of this kind.
     *
     * Three buckets rather than eight keys: a body is kept because somebody may
     * dispute it, and there are only three honest answers to how long that is.
     */
    public function retentionSettingKey(): string
    {
        return match ($this) {
            // An accounting or accreditation dispute can arrive a year later.
            self::Purchase, self::Credential => 'email.retain_financial_days',
            // Reproducible, and uncontested after a quarter.
            self::Account, self::Reminder, self::Delivery, self::Membership, self::Internal => 'email.retain_operational_days',
            self::Marketing => 'email.retain_marketing_days',
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

    /**
     * The categories a person can decline, for the preference screen.
     *
     * @return array<int, self>
     */
    public static function declinable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $category): bool => $category->mayBeDeclinedByRecipient(),
        ));
    }
}

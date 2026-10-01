<?php

declare(strict_types=1);

namespace App\Email;

use App\Enums\EmailCategory;
use App\Enums\EmailTier;

/**
 * Every email this website can send.
 *
 * This list does three jobs that were otherwise going to be done three times.
 * It is the Emails screen. It generates the `email.enabled.*` setting keys, so a
 * toggle cannot exist without an email or an email without a toggle. And it is
 * the answer to "what does this site email people?" — a question the client will
 * ask repeatedly and which, before this, nothing in the codebase could answer.
 *
 * **Entries with no mailable are deliberate.** Twenty-six of these need a
 * trigger that does not exist yet — there is no cart, no checkout and no learner
 * dashboard. Listing them with what blocks them is worth more than leaving them
 * out: it is the difference between a client seeing the shape of what is coming
 * and a client discovering in month four that nothing tells a customer their
 * recording is ready.
 */
final class EmailCatalogue
{
    /** @var array<string, EmailDefinition>|null */
    private static ?array $cache = null;

    /** @return array<string, EmailDefinition> */
    public static function all(): array
    {
        return self::$cache ??= collect(self::declare())
            ->keyBy(fn (EmailDefinition $email): string => $email->key)
            ->all();
    }

    public static function find(string $key): ?EmailDefinition
    {
        return self::all()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<int, EmailDefinition> */
    public static function inCategory(EmailCategory $category): array
    {
        return array_values(array_filter(
            self::all(),
            fn (EmailDefinition $email): bool => $email->category === $category,
        ));
    }

    /**
     * The ones with a trigger behind them.
     *
     * @return array<int, EmailDefinition>
     */
    public static function built(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (EmailDefinition $email): bool => $email->isBuilt(),
        ));
    }

    /**
     * The ones whose tier allows a switch.
     *
     * Includes emails with no trigger behind them yet, because the Emails screen
     * shows those too — with what blocks them rather than with a switch.
     *
     * @return array<int, EmailDefinition>
     */
    public static function switchable(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (EmailDefinition $email): bool => $email->tier->isSwitchable(),
        ));
    }

    /**
     * The ones that actually get an on/off setting.
     *
     * Switchable **and** built. A toggle for an email that cannot send is a
     * control that does nothing — the client changes it, sees no effect, and
     * concludes the software is broken. That failure already happened once with
     * nine settings wired to nothing, and SettingRegistryTest exists because of it.
     *
     * Currently empty, which is worth knowing rather than hiding: every email
     * with a trigger behind it today is one that must always send.
     *
     * @return array<int, EmailDefinition>
     */
    public static function togglable(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (EmailDefinition $email): bool => $email->tier->isSwitchable() && $email->isBuilt(),
        ));
    }

    /** Only for tests that rebuild after touching the list. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<int, EmailDefinition>
     */
    private static function declare(): array
    {
        return [
            ...self::account(),
            ...self::purchase(),
            ...self::credentials(),
            ...self::reminders(),
            ...self::delivery(),
            ...self::membership(),
            ...self::marketing(),
            ...self::internal(),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function account(): array
    {
        return [
            new EmailDefinition(
                key: 'account.verify_email',
                name: 'Verify your email address',
                category: EmailCategory::Account,
                tier: EmailTier::Required,
                trigger: 'Somebody registers, or changes the address on their account.',
                lockReason: 'Without it nobody can prove they own the address they signed up with.',
                hasExpiringLinks: true,
                /*
                 * Not an oversight and not a template away. Fortify is installed
                 * but its service provider is **not registered** — bootstrap/
                 * providers.php lists two providers — so there is no
                 * `verification.verify` route for a link to point at, and
                 * `Features::emailVerification()` is commented out behind that.
                 * Registering Fortify is a decision about the public auth
                 * surface, which belongs with the learner area in P5.
                 */
                blockedBy: 'Fortify is not registered, so there is no verification route (P5).',
            ),
            new EmailDefinition(
                key: 'account.password_reset',
                name: 'Reset your password',
                category: EmailCategory::Account,
                tier: EmailTier::Required,
                trigger: 'Somebody uses "forgotten password", on the site or in the admin.',
                mailable: \App\Mail\ResetPassword::class,
                lockReason: 'The only way back into a locked-out account. §9 makes Fortify the single owner of password resets.',
                hasExpiringLinks: true,
                notResendableReason: 'The token is single-use and expires. Use "Send reset link" on the Staff screen, which issues a fresh one.',
            ),
            new EmailDefinition(
                key: 'account.password_changed',
                name: 'Your password was changed',
                category: EmailCategory::Account,
                tier: EmailTier::Required,
                trigger: 'A password is changed, whether from the profile screen or a reset link.',
                mailable: \App\Mail\PasswordChanged::class,
                lockReason: 'If somebody else changed it, this is how the owner finds out.',
            ),
            new EmailDefinition(
                key: 'account.two_factor_cleared',
                name: 'An admin cleared your two-factor setup',
                category: EmailCategory::Account,
                tier: EmailTier::Required,
                trigger: 'A Super Admin resets somebody\'s two-factor from the Staff screen.',
                mailable: \App\Mail\TwoFactorCleared::class,
                lockReason: 'Their account is on a password alone until they set it up again, and they may not have asked for this.',
            ),
            new EmailDefinition(
                key: 'account.two_factor_enabled',
                name: 'Two-factor was turned on',
                category: EmailCategory::Account,
                tier: EmailTier::Required,
                trigger: 'Somebody confirms two-factor on their own profile.',
                lockReason: 'A security change to their own account.',
                blockedBy: 'Needs a hook into Filament\'s two-factor confirmation.',
            ),
            new EmailDefinition(
                key: 'account.new_device_sign_in',
                name: 'A new sign-in',
                category: EmailCategory::Account,
                tier: EmailTier::OptIn,
                trigger: 'Somebody signs in from a device not seen before.',
                blockedBy: 'Needs device fingerprinting, which nothing records yet.',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function purchase(): array
    {
        return [
            new EmailDefinition(
                key: 'purchase.order_confirmation',
                name: 'Order confirmation and invoice',
                category: EmailCategory::Purchase,
                tier: EmailTier::Required,
                trigger: 'A payment is verified and the order is paid.',
                lockReason: 'A customer who paid and received nothing is §14 #6 — the predecessor\'s worst defect, reachable from an admin toggle.',
                blockedBy: 'Checkout (P3).',
            ),
            new EmailDefinition(
                key: 'purchase.payment_declined',
                name: 'Payment declined',
                category: EmailCategory::Purchase,
                tier: EmailTier::Required,
                trigger: 'A capture fails at the gateway.',
                lockReason: 'They think they have bought something. They have not.',
                blockedBy: 'Checkout (P3).',
            ),
            new EmailDefinition(
                key: 'purchase.refund_issued',
                name: 'Your refund has been sent',
                category: EmailCategory::Purchase,
                tier: EmailTier::Required,
                trigger: 'A refund reaches the gateway successfully.',
                mailable: \App\Mail\RefundIssued::class,
                lockReason: 'Money has left. The customer is owed the record of it, and their bank statement will not explain it.',
            ),
            new EmailDefinition(
                key: 'purchase.seat_assigned',
                name: 'A seat was booked for you',
                category: EmailCategory::Purchase,
                tier: EmailTier::Required,
                trigger: 'A multi-seat order names an attendee who is not the purchaser.',
                lockReason: 'The attendee did not buy it and has no other way of knowing it exists.',
                blockedBy: 'Attendee capture at checkout (P3).',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function credentials(): array
    {
        return [
            new EmailDefinition(
                key: 'credential.certificate_issued',
                name: 'Your certificate is ready',
                category: EmailCategory::Credential,
                tier: EmailTier::Required,
                trigger: 'A certificate is issued, automatically or by an admin.',
                lockReason: 'The credits are what they bought (§1). Withholding this withholds the product.',
                hasExpiringLinks: true,
                blockedBy: 'Learner dashboard (P5), for somewhere to download it from.',
            ),
            new EmailDefinition(
                key: 'credential.certificate_revoked',
                name: 'A certificate has been withdrawn',
                category: EmailCategory::Credential,
                tier: EmailTier::Required,
                trigger: 'An admin revokes a certificate.',
                lockReason: 'They may have already filed those credits with their accrediting body.',
                blockedBy: 'Tier 2.',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function reminders(): array
    {
        return [
            new EmailDefinition(
                key: 'reminder.registration_confirmed',
                name: 'You are registered',
                category: EmailCategory::Reminder,
                tier: EmailTier::Required,
                trigger: 'Access is granted to a live session.',
                lockReason: 'Confirms a date somebody is about to block out.',
                blockedBy: 'Join-link release (P5).',
            ),
            new EmailDefinition(
                key: 'reminder.seven_days',
                name: 'Reminder — 7 days to go',
                category: EmailCategory::Reminder,
                tier: EmailTier::Optional,
                trigger: 'Seven days before a session, to everyone registered.',
                blockedBy: 'Sequence scheduler (Tier 4).',
            ),
            new EmailDefinition(
                key: 'reminder.one_day',
                name: 'Reminder — tomorrow',
                category: EmailCategory::Reminder,
                tier: EmailTier::Optional,
                trigger: 'The day before a session.',
                blockedBy: 'Sequence scheduler (Tier 4).',
            ),
            new EmailDefinition(
                key: 'reminder.one_hour',
                name: 'Reminder — starting in an hour, with the join link',
                category: EmailCategory::Reminder,
                tier: EmailTier::Optional,
                trigger: 'An hour before a session.',
                hasExpiringLinks: true,
                blockedBy: 'Sequence scheduler (Tier 4).',
            ),
            new EmailDefinition(
                key: 'reminder.session_rescheduled',
                name: 'A session has moved',
                category: EmailCategory::Reminder,
                tier: EmailTier::Required,
                trigger: 'A webinar\'s date or time changes while people hold access to it.',
                lockReason: 'They have put it in a diary. Silence means they attend nothing.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'reminder.session_cancelled',
                name: 'A session has been cancelled',
                category: EmailCategory::Reminder,
                tier: EmailTier::Required,
                trigger: 'A webinar is cancelled while people hold access to it.',
                lockReason: 'Somebody has blocked out an afternoon for this.',
                blockedBy: 'Tier 2.',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function delivery(): array
    {
        return [
            new EmailDefinition(
                key: 'delivery.recording_ready',
                name: 'Your recording is ready',
                category: EmailCategory::Delivery,
                tier: EmailTier::Optional,
                trigger: 'A recording asset becomes available to somebody holding access.',
                hasExpiringLinks: true,
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'delivery.transcript_ready',
                name: 'Your transcript is ready',
                category: EmailCategory::Delivery,
                tier: EmailTier::Optional,
                trigger: 'A transcript asset becomes available to somebody holding access.',
                hasExpiringLinks: true,
                blockedBy: 'Tier 2.',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function membership(): array
    {
        return [
            new EmailDefinition(
                key: 'membership.welcome',
                name: 'Welcome to your plan',
                category: EmailCategory::Membership,
                tier: EmailTier::Optional,
                trigger: 'A subscription becomes active.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'membership.renewal_due',
                name: 'Your plan renews soon',
                category: EmailCategory::Membership,
                tier: EmailTier::Optional,
                trigger: 'A set number of days before a subscription renews.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'membership.payment_failed',
                name: 'We could not take your payment',
                category: EmailCategory::Membership,
                tier: EmailTier::Required,
                trigger: 'A subscription payment fails and dunning begins.',
                lockReason: 'Their access is about to stop and only they can fix it.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'membership.plan_changed',
                name: 'Your plan has changed',
                category: EmailCategory::Membership,
                tier: EmailTier::Optional,
                trigger: 'An upgrade or downgrade completes.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'membership.cancelled',
                name: 'Your plan has been cancelled',
                category: EmailCategory::Membership,
                tier: EmailTier::Required,
                trigger: 'A subscription is cancelled.',
                lockReason: 'It says when their access actually ends, which is rarely the same day.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'membership.allowance_exhausted',
                name: 'You have used your allowance',
                category: EmailCategory::Membership,
                tier: EmailTier::Optional,
                trigger: 'A plan allowance for a format is fully spent for the period.',
                blockedBy: 'Tier 2.',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function marketing(): array
    {
        return [
            new EmailDefinition(
                key: 'marketing.list_confirm',
                name: 'Confirm your email address',
                category: EmailCategory::Marketing,
                tier: EmailTier::Required,
                trigger: 'Somebody joins the mailing list while double opt-in is on.',
                mailable: \App\Mail\ConfirmMailingList::class,
                // Required rather than optional although it is marketing: with
                // double opt-in on and this switched off, every signup waits
                // forever for an email nothing sends. The setting that turns
                // double opt-in on is the switch; this is not a second one.
                lockReason: 'With double opt-in on, a signup that never receives this waits forever. Turn off double opt-in instead.',
                hasExpiringLinks: true,
                /*
                 * Required so an admin cannot break double opt-in by switching it
                 * off — but **not** owed to somebody who reported us as spam.
                 * Without saying so, being Required would exempt it from the
                 * complaint check as a side effect, and a spam complainer would
                 * receive a marketing confirmation. Subscribe already refuses to
                 * resubscribe them from a form; this covers the case where staff
                 * do it deliberately.
                 */
                owedToRecipient: false,
            ),
            // Resendable, unlike the other signed-link emails: the confirmation
            // route is signed but not single-use, and "I never got the
            // confirmation" is the most common reason anybody opens this log.
            new EmailDefinition(
                key: 'marketing.list_welcome',
                name: 'Welcome to the mailing list',
                category: EmailCategory::Marketing,
                tier: EmailTier::Optional,
                trigger: 'An address is confirmed onto the list.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'marketing.win_back',
                name: 'We have not seen you in a while',
                category: EmailCategory::Marketing,
                tier: EmailTier::OptIn,
                trigger: 'A customer has not watched or bought anything for 90 days.',
                blockedBy: 'Sequence scheduler (Tier 4).',
            ),
            new EmailDefinition(
                key: 'marketing.abandoned_cart_four_hours',
                name: 'You left something behind — 4 hours',
                category: EmailCategory::Marketing,
                tier: EmailTier::OptIn,
                trigger: 'A cart is left untouched for four hours.',
                blockedBy: 'There is no cart yet (P3).',
            ),
            new EmailDefinition(
                key: 'marketing.abandoned_cart_one_day',
                name: 'You left something behind — 24 hours',
                category: EmailCategory::Marketing,
                tier: EmailTier::OptIn,
                trigger: 'A cart is left untouched for a day.',
                blockedBy: 'There is no cart yet (P3).',
            ),
        ];
    }

    /** @return array<int, EmailDefinition> */
    private static function internal(): array
    {
        return [
            new EmailDefinition(
                key: 'internal.staff_invitation',
                name: 'You have been given admin access',
                category: EmailCategory::Internal,
                tier: EmailTier::Required,
                trigger: 'Somebody is invited on the Staff screen, or an existing account is given a role.',
                mailable: \App\Mail\StaffInvitation::class,
                lockReason: 'Without it they receive a bare password-reset link they never asked for, and no explanation.',
                hasExpiringLinks: true,
                notResendableReason: 'The link is single-use. Use "Send invitation again" on the Staff screen, which issues a fresh one.',
            ),
            new EmailDefinition(
                key: 'internal.refund_scheduled',
                name: 'A refund has been scheduled',
                category: EmailCategory::Internal,
                tier: EmailTier::Required,
                trigger: 'A refund is agreed. Goes to every Super Admin, including whoever raised it.',
                mailable: \App\Mail\RefundScheduled::class,
                lockReason: 'The cooling-off window is the only chance to stop money leaving. This is what announces it.',
            ),
            new EmailDefinition(
                key: 'internal.new_inquiry',
                name: 'A new inquiry arrived',
                category: EmailCategory::Internal,
                tier: EmailTier::Optional,
                trigger: 'Somebody uses the contact form.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'internal.comment_pending',
                name: 'A comment is waiting for moderation',
                category: EmailCategory::Internal,
                tier: EmailTier::Optional,
                trigger: 'A blog comment lands in the moderation queue.',
                blockedBy: 'Tier 2.',
            ),
            new EmailDefinition(
                key: 'internal.daily_digest',
                name: 'Daily digest',
                category: EmailCategory::Internal,
                tier: EmailTier::OptIn,
                trigger: 'Once a day: orders taken, inquiries received, comments waiting.',
                blockedBy: 'Sequence scheduler (Tier 4).',
            ),
        ];
    }
}

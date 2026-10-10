<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\Format;
use App\Support\CompletionRules;
use App\Support\OrderNumberFormat;

/**
 * Every setting this application reads.
 *
 * **This list is the contract.** `Setting::get()` refuses a key that is not
 * here when the app is not in production, so a setting cannot be introduced in
 * code and quietly have no screen — which is exactly what had happened to
 * eleven of them, including the refund cooling-off window, a money control
 * nobody could see or change. Two of those eleven were mine.
 *
 * What this does not do is build the form. The order-number section has live
 * previews and rules that only make sense across three fields at once; the
 * promo bar hides most of itself behind a toggle. Generating those from
 * metadata would mean re-inventing Filament badly. The form stays hand-written
 * and this stays the source of truth for keys, types, defaults and ownership.
 */
final class SettingRegistry
{
    /** @var array<string, SettingDefinition>|null */
    private static ?array $cache = null;

    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return self::$cache ??= collect(self::declare())
            ->keyBy(fn (SettingDefinition $setting): string => $setting->key)
            ->all();
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<int, SettingDefinition> */
    public static function forGroup(SettingGroup $group): array
    {
        return array_values(array_filter(
            self::all(),
            fn (SettingDefinition $setting): bool => $setting->group === $group,
        ));
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Only for tests that need to rebuild after touching the list. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<int, SettingDefinition>
     */
    private static function declare(): array
    {
        return [
            ...self::business(),
            ...self::brand(),
            ...self::commerce(),
            ...self::catalogue(),
            ...self::certificates(),
            ...self::email(),
        ];
    }

    /** @return array<int, SettingDefinition> */
    private static function business(): array
    {
        return [
            new SettingDefinition(
                key: 'business.name',
                group: SettingGroup::Business,
                label: 'Trading name',
                type: 'string',
                default: config('app.name'),
                help: 'What customers call you. Shown in the footer and on certificates.',
            ),
            new SettingDefinition(
                key: 'business.legal_name',
                group: SettingGroup::Business,
                label: 'Registered name',
                type: 'string',
                help: 'The entity name for invoices, if it differs from the trading name.',
            ),
            new SettingDefinition(
                key: 'business.tagline',
                group: SettingGroup::Business,
                label: 'Tagline',
                type: 'string',
                default: 'Professional compliance training for HR, payroll, and operations teams across the United States.',
                help: 'The line under the logo in the footer, on every page.',
                input: 'textarea',
            ),
            new SettingDefinition(
                key: 'business.address',
                group: SettingGroup::Business,
                label: 'Address',
                type: 'string',
                help: 'Shown in the footer and on invoices. One line per line.',
                input: 'textarea',
            ),
            new SettingDefinition(
                key: 'business.email',
                group: SettingGroup::Business,
                label: 'Contact email',
                type: 'string',
                help: 'The address customers are told to write to.',
                input: 'email',
            ),
            new SettingDefinition(
                key: 'business.phone',
                group: SettingGroup::Business,
                label: 'Phone',
                type: 'string',
                input: 'tel',
                maxLength: 40,
            ),
            new SettingDefinition(
                key: 'business.linkedin_url',
                group: SettingGroup::Business,
                label: 'LinkedIn',
                type: 'string',
                help: 'Leave blank to hide the icon rather than link it nowhere.',
                input: 'url',
            ),
            new SettingDefinition(
                key: 'business.instagram_url',
                group: SettingGroup::Business,
                label: 'Instagram',
                type: 'string',
                input: 'url',
            ),
            new SettingDefinition(
                key: 'business.youtube_url',
                group: SettingGroup::Business,
                label: 'YouTube',
                type: 'string',
                input: 'url',
            ),
            /*
             * Read by every report. The app runs on UTC, which is right for
             * storage and wrong for "what did we take today" — a US business
             * asking that at 8pm would otherwise be shown tomorrow.
             */
            new SettingDefinition(
                key: 'reports.timezone',
                group: SettingGroup::Business,
                label: 'Business timezone',
                type: 'string',
                default: 'America/New_York',
                help: 'Decides what "today" means on every report. The server stores everything in UTC regardless.',
                input: 'timezone',
            ),
        ];
    }

    /** @return array<int, SettingDefinition> */
    private static function brand(): array
    {
        return [
            new SettingDefinition('brand.logo_media_id', SettingGroup::Brand, 'Logo', 'media_id'),
            new SettingDefinition('brand.logo_dark_media_id', SettingGroup::Brand, 'Logo (dark backgrounds)', 'media_id'),
            new SettingDefinition('brand.favicon_media_id', SettingGroup::Brand, 'Favicon', 'media_id'),
            new SettingDefinition('brand.og_default_media_id', SettingGroup::Brand, 'Default social image', 'media_id'),

            new SettingDefinition('promo_bar.enabled', SettingGroup::Brand, 'Show the promo bar', 'boolean', true),
            new SettingDefinition(
                key: 'promo_bar.text',
                group: SettingGroup::Brand,
                label: 'Message',
                type: 'string',
                // The copy itself, not an empty string. The field is required,
                // so a blank default meant a fresh install could not save the
                // settings screen until somebody filled this in.
                default: 'Purchase any webinar and use code :code at checkout to save $10.',
            ),
            new SettingDefinition('promo_bar.code', SettingGroup::Brand, 'Coupon code', 'string', ''),
            new SettingDefinition('promo_bar.background_color', SettingGroup::Brand, 'Background colour', 'color', '#0c4d80'),
            new SettingDefinition('promo_bar.text_color', SettingGroup::Brand, 'Text colour', 'color', '#ffffff'),
            new SettingDefinition('promo_bar.font_size', SettingGroup::Brand, 'Font size', 'integer', 14),
        ];
    }

    /** @return array<int, SettingDefinition> */
    private static function commerce(): array
    {
        return [
            new SettingDefinition(
                key: 'orders.number_format',
                group: SettingGroup::Commerce,
                label: 'Order number format',
                type: 'string',
                default: OrderNumberFormat::DEFAULT_PATTERN,
            ),
            new SettingDefinition('orders.number_padding', SettingGroup::Commerce, 'Sequence digits', 'integer', 4),
            new SettingDefinition('orders.number_reset', SettingGroup::Commerce, 'Restart the sequence', 'string', 'yearly'),
            new SettingDefinition('orders.year_start_month', SettingGroup::Commerce, 'Accounting year starts in', 'integer', 1),

            /*
             * The orphan that mattered most. A refund is agreed, then sits for
             * this long before it is actually sent — the window in which a
             * mistake can still be caught. It has been live and invisible.
             */
            new SettingDefinition(
                key: 'orders.refund_delay_minutes',
                group: SettingGroup::Commerce,
                label: 'Hold refunds for',
                type: 'integer',
                default: \App\Actions\Payments\RequestRefund::DEFAULT_DELAY_MINUTES,
                help: 'How long an agreed refund waits before it is sent, so a mistake can be cancelled. Zero sends immediately.',
                input: 'number',
                // A day at the outside. Longer stops being a safety window
                // and starts being a customer wondering where their money is.
                range: [0, 1440],
            ),
        ];
    }

    /** @return array<int, SettingDefinition> */
    private static function catalogue(): array
    {
        $formats = array_map(
            fn (Format $format): SettingDefinition => new SettingDefinition(
                key: $format->descriptionSettingKey(),
                group: SettingGroup::Catalogue,
                label: $format->label(),
                type: 'string',
                default: $format->defaultDescription(),
                help: 'Shown beside this format wherever it is sold.',
                input: 'textarea',
            ),
            Format::cases(),
        );

        return [
            ...$formats,

            new SettingDefinition(
                key: 'webinars.default_instructions',
                group: SettingGroup::Catalogue,
                label: 'Default joining instructions',
                type: 'string',
                help: 'Used by any webinar that has not written its own. Left blank, nothing is shown.',
                input: 'textarea',
            ),
            new SettingDefinition(
                key: 'webinars.qa_note',
                group: SettingGroup::Catalogue,
                label: 'Q&A note',
                type: 'string',
                default: 'During the Q&A session following the live event, ask a question and get a direct response from our expert speaker.',
                help: 'The paragraph about asking questions, shown on every live webinar page.',
                input: 'textarea',
            ),
        ];
    }

    /** @return array<int, SettingDefinition> */
    private static function certificates(): array
    {
        return [
            new SettingDefinition(
                key: CompletionRules::LIVE_DELAY_KEY,
                group: SettingGroup::Certificates,
                label: 'Issue live certificates after',
                type: 'integer',
                default: CompletionRules::DEFAULT_LIVE_DELAY_HOURS,
                help: 'Hours after a live session before everyone holding live access is certified. An accrediting body can override this on its own record.',
                input: 'number',
                // Four weeks at the outside: past that a learner has long since
                // asked where their certificate is.
                range: [0, 672],
            ),
            new SettingDefinition(
                key: CompletionRules::WATCH_TOLERANCE_KEY,
                group: SettingGroup::Certificates,
                label: 'Allow skipping',
                type: 'integer',
                default: CompletionRules::DEFAULT_WATCH_TOLERANCE_MINUTES,
                help: 'Minutes of a recording that may be left unwatched and still count as completing it.',
                input: 'number',
                // CompletionRules keeps a one-minute floor whatever this says,
                // so a large tolerance cannot certify somebody who pressed play.
                range: [0, 60],
            ),
        ];
    }

    /** @return array<int, SettingDefinition> */
    private static function email(): array
    {
        return [
            new SettingDefinition(
                key: 'mailing_list.double_opt_in',
                group: SettingGroup::Email,
                label: 'Make people confirm their address',
                type: 'boolean',
                default: false,
                help: 'Sends a confirmation link before anybody joins the list. Safe to turn on now that the confirmation email exists — though if Mailchimp runs its own opt-in, leaving this off avoids asking people to confirm twice.',
                input: 'toggle',
            ),

            // Who it comes from -------------------------------------------------

            new SettingDefinition(
                key: 'email.from_name',
                group: SettingGroup::Email,
                label: 'Sender name',
                type: 'string',
                default: config('app.name'),
                help: 'What recipients see as the sender. Worth keeping as the business name: the address itself is a gmail.com one, and the name is the part that reads as legitimate.',
            ),
            new SettingDefinition(
                key: 'email.from_address',
                group: SettingGroup::Email,
                label: 'Sender address',
                type: 'string',
                default: config('mail.from.address'),
                help: 'Must be the Gmail account that sends. Keep it a mailbox somebody reads — bounces come back here as ordinary emails, and that is the only bounce signal this setup has.',
                input: 'email',
            ),
            new SettingDefinition(
                key: 'email.reply_to',
                group: SettingGroup::Email,
                label: 'Reply-to address',
                type: 'string',
                default: '',
                help: 'Where replies go, if different from the sender. B2B buyers reply to reminders with questions, so this should reach a person.',
                input: 'email',
            ),
            new SettingDefinition(
                key: 'email.monitoring_bcc',
                group: SettingGroup::Email,
                label: 'Blind-copy everything to',
                type: 'string',
                default: '',
                help: 'Optional. A copy of every email sent, for monitoring. Leave empty unless somebody will actually read it — it counts against the daily sending allowance twice over.',
                input: 'email',
            ),

            // How much can go out ------------------------------------------------

            new SettingDefinition(
                key: 'email.sending_limit',
                group: SettingGroup::Email,
                label: 'Daily sending limit',
                type: 'string',
                default: 'auto',
                help: 'A free Gmail account allows ~500 messages a day and blocks the account for a day if you go over. "Automatic" enforces the limits below only while sending through Gmail. Switch to "Always" if your provider has its own daily limit (e.g. Amazon SES in sandbox), or "Never" to lift it entirely.',
                input: 'select',
                options: [
                    'auto' => 'Automatic — only when sending through Gmail',
                    'always' => 'Always enforce the limits below',
                    'never' => 'Never — my provider has no daily limit',
                ],
            ),

            new SettingDefinition(
                key: 'email.daily_cap',
                group: SettingGroup::Email,
                label: 'Messages per 24 hours',
                type: 'integer',
                default: 450,
                help: 'A consumer Gmail account allows around 500 a day, and going over blocks the whole account for about 24 hours — password resets included. Below 500 on purpose.',
                input: 'number',
                range: [1, 2000],
            ),
            new SettingDefinition(
                key: 'email.bulk_cap',
                group: SettingGroup::Email,
                label: 'Of which, bulk messages',
                type: 'integer',
                default: 300,
                help: 'The ceiling for reminders and marketing. The gap between this and the daily figure is reserved, so a reminder blast can never use up the allowance a password reset needs.',
                input: 'number',
                range: [1, 2000],
            ),
            new SettingDefinition(
                key: 'email.per_minute_cap',
                group: SettingGroup::Email,
                label: 'Messages per minute',
                type: 'integer',
                default: 15,
                help: 'Gmail answers a burst with a temporary block. Anything over this waits rather than failing.',
                input: 'number',
                range: [1, 120],
            ),

            // Emergencies and environments ----------------------------------------

            new SettingDefinition(
                key: 'email.sending_halted',
                group: SettingGroup::Email,
                label: 'Stop all sending',
                type: 'boolean',
                default: false,
                help: 'For the day a template goes out wrong. Everything is recorded as not sent, nothing is lost, and switching it back on lets the queue drain. The alternative is a deploy under pressure.',
                input: 'toggle',
            ),
            new SettingDefinition(
                key: 'email.allow_list',
                group: SettingGroup::Email,
                label: 'Only send to these addresses (outside production)',
                type: 'string',
                default: '',
                help: 'Addresses or domains, separated by commas. Ignored in production. The seeded database holds realistic customer addresses, and without this a mistake on staging emails strangers.',
            ),

            // How long the log keeps bodies ---------------------------------------

            new SettingDefinition(
                key: 'email.retain_financial_days',
                group: SettingGroup::Email,
                label: 'Keep receipts and certificates for (days)',
                type: 'integer',
                default: 730,
                help: 'How long the full content of money and certificate emails is kept. A finance or accreditation query can arrive a year later. The record of the send itself is kept permanently either way.',
                input: 'number',
                range: [30, 3650],
            ),
            new SettingDefinition(
                key: 'email.retain_operational_days',
                group: SettingGroup::Email,
                label: 'Keep reminders and notices for (days)',
                type: 'integer',
                default: 90,
                help: 'Reminders, recordings, membership notices and password emails. Reproducible, and uncontested after a quarter.',
                input: 'number',
                range: [7, 3650],
            ),
            new SettingDefinition(
                key: 'email.retain_marketing_days',
                group: SettingGroup::Email,
                label: 'Keep marketing content for (days)',
                type: 'integer',
                default: 30,
                help: 'Nobody disputes a newsletter.',
                input: 'number',
                range: [7, 3650],
            ),

            ...self::emailToggles(),
        ];
    }

    /**
     * One on/off key per switchable email, generated from the catalogue.
     *
     * Generated rather than typed out, so a toggle cannot exist without an email
     * or an email without a toggle — the same drift that left eleven settings
     * readable in code with no screen to change them.
     *
     * Required emails are absent by design. Not a key defaulting to true: a key
     * that exists can be set to false, and EmailTier::Required exists precisely
     * so order confirmations and password resets cannot be switched off from an
     * admin screen (§14 #6).
     *
     * `togglable()` rather than `switchable()`, so an email with no trigger
     * behind it gets no key either — a setting wired to nothing is the mistake
     * SettingRegistryTest's second guard was written for.
     *
     * @return array<int, SettingDefinition>
     */
    private static function emailToggles(): array
    {
        return array_map(
            static fn (\App\Email\EmailDefinition $email): SettingDefinition => new SettingDefinition(
                key: $email->settingKey(),
                group: SettingGroup::Email,
                label: $email->name,
                type: 'boolean',
                default: $email->tier->defaultEnabled(),
                help: $email->trigger,
                input: 'toggle',
            ),
            \App\Email\EmailCatalogue::togglable(),
        );
    }
}

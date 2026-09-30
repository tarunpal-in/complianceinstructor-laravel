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
                key: 'business.address',
                group: SettingGroup::Business,
                label: 'Address',
                type: 'string',
                help: 'Appears on invoices. One per line.',
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
                help: 'Sends a confirmation link before anybody joins the list. Turn this on once transactional mail is wired up — until then a signup would wait forever for an email nothing can send.',
                input: 'toggle',
            ),
        ];
    }
}

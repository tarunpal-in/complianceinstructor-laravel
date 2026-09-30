<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * Who the business is, for anything that has to say so.
 *
 * A reader for the Business settings group, rather than
 * `Setting::declared('business.phone')` scattered through Blade. Two reasons
 * beyond tidiness: the socials need one place that knows which of them are
 * actually set, so the footer can hide an icon rather than link it nowhere;
 * and the name falls back to `config('app.name')`, which is what every view
 * used before these settings existed — so nothing renders blank on an install
 * where the client has not filled the form in yet.
 */
final class Business
{
    /** What customers call the business. */
    public static function name(): string
    {
        $name = Setting::declared('business.name');

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : (string) config('app.name');
    }

    /**
     * The registered entity, for an invoice.
     *
     * Falls back to the trading name, because most small businesses trade
     * under their registered name and making the client type it twice to get
     * a correct invoice is a good way to end up with a blank one.
     */
    public static function legalName(): string
    {
        $name = Setting::declared('business.legal_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : self::name();
    }

    public static function tagline(): ?string
    {
        return self::text('business.tagline');
    }

    public static function email(): ?string
    {
        return self::text('business.email');
    }

    public static function phone(): ?string
    {
        return self::text('business.phone');
    }

    public static function address(): ?string
    {
        return self::text('business.address');
    }

    /**
     * The address as lines, for a footer or an invoice.
     *
     * @return array<int, string>
     */
    public static function addressLines(): array
    {
        $address = self::address();

        if ($address === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $address) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * The social accounts that have actually been set.
     *
     * Keyed by the icon name, so a view can loop rather than repeat itself
     * three times and forget one. An account with no URL is absent from this
     * list entirely — the footer used to link all three at `#top`, which is
     * worse than not showing them, because a visitor clicks and nothing
     * happens.
     *
     * @return array<string, string>
     */
    public static function socials(): array
    {
        $accounts = [
            'linkedin' => self::text('business.linkedin_url'),
            'instagram' => self::text('business.instagram_url'),
            'youtube' => self::text('business.youtube_url'),
        ];

        return array_filter($accounts, fn (?string $url): bool => $url !== null);
    }

    /** Whether there is enough to render a contact block at all. */
    public static function hasContactDetails(): bool
    {
        return self::email() !== null || self::phone() !== null || self::addressLines() !== [];
    }

    private static function text(string $key): ?string
    {
        $value = Setting::declared($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

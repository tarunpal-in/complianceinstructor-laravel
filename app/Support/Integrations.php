<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * The third-party IDs the client pastes in Settings → Integrations.
 *
 * Each accessor returns a validated value or null, so a template can ask "is
 * there a GTM container?" and render the snippet only when there is. Validation
 * is deliberately loose — the exact format is Google's or Tawk's to change — but
 * tight enough that a value pasted with the surrounding tag, or an obviously
 * wrong one, does not reach the page and break it.
 */
final class Integrations
{
    /** GTM-XXXXXX, or null. */
    public static function gtmId(): ?string
    {
        return self::matching('integrations.gtm_id', '/^GTM-[A-Z0-9]+$/i');
    }

    /** G-XXXXXXXX, or null. Ignored when GTM is set, to avoid double-counting. */
    public static function ga4Id(): ?string
    {
        if (self::gtmId() !== null) {
            return null;
        }

        return self::matching('integrations.google_analytics_id', '/^G-[A-Z0-9]+$/i');
    }

    /** The Search Console verification code, or null. */
    public static function searchConsoleCode(): ?string
    {
        $value = trim((string) Setting::get('integrations.gsc_verification', ''));

        // Accept the bare code only; a value with angle brackets is a pasted tag
        // and would break the meta element.
        return $value !== '' && ! str_contains($value, '<') ? $value : null;
    }

    /** The Tawk.to widget path (property/widget), or null. */
    public static function tawkToId(): ?string
    {
        $value = trim((string) Setting::get('integrations.tawkto_id', ''));

        // Expect "property/widget". Reject anything with a scheme or markup — a
        // pasted full script — so only the embed path reaches the page.
        return preg_match('#^[A-Za-z0-9]+/[A-Za-z0-9]+$#', $value) === 1 ? $value : null;
    }

    /** Whether anything at all needs rendering, so the partials can bail early. */
    public static function any(): bool
    {
        return self::gtmId() !== null
            || self::ga4Id() !== null
            || self::searchConsoleCode() !== null
            || self::tawkToId() !== null;
    }

    private static function matching(string $key, string $pattern): ?string
    {
        $value = trim((string) Setting::get($key, ''));

        return $value !== '' && preg_match($pattern, $value) === 1 ? $value : null;
    }
}

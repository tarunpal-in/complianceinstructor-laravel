<?php

declare(strict_types=1);

namespace App\Import\Maps;

use App\Enums\Format;
use RuntimeException;

/**
 * The WooCommerce `pa_addons` term → the atomic formats it grants.
 *
 * This is the combo-expansion the whole migration turns on: the old store sold
 * fixed bundles ("Live + Recorded + E-Transcript") as single variations, while
 * the new model grants each format separately (§5.2). One bundle variation
 * therefore becomes several entitlements — the mapping that made webinar 10820's
 * 24 orders produce 27 entitlement rows.
 *
 * Keyed by the term *slug* (stable), not its display label (which varies in
 * case: "LIVE", "Live"). Unmapped slugs throw rather than silently granting
 * nothing — a new combo on the live site must be noticed, not swallowed (§14
 * #12's loose-string lesson).
 */
final class AddonFormats
{
    /**
     * @var array<string, array<int, Format>>
     */
    private const MAP = [
        'live' => [Format::Live],
        'recorded' => [Format::Recorded],
        'e-transcript' => [Format::Transcript],
        'transcript' => [Format::Transcript],
        'download-course' => [Format::Download],

        'live-recorded' => [Format::Live, Format::Recorded],
        'recordede-transcript' => [Format::Recorded, Format::Transcript],
        'livee-transcript' => [Format::Live, Format::Transcript],
        'liverecordede-transcript' => [Format::Live, Format::Recorded, Format::Transcript],

        // Legacy physical formats from the earliest orders. A DVD is the
        // recording on a disc, so it maps to the recorded format.
        'dvd' => [Format::Recorded],
        'dvd-transcript' => [Format::Recorded, Format::Transcript],
    ];

    /**
     * The atomic formats a purchased combo grants.
     *
     * @return array<int, Format>
     */
    public static function forSlug(string $slug): array
    {
        $slug = strtolower(trim($slug));

        if (! isset(self::MAP[$slug])) {
            throw new RuntimeException("Unmapped pa_addons term '{$slug}'. Add it to ".self::class.' before importing.');
        }

        return self::MAP[$slug];
    }

    public static function knows(string $slug): bool
    {
        return isset(self::MAP[strtolower(trim($slug))]);
    }

    /** Whether this combo includes the live format (so it is a Live-type SKU). */
    public static function includesLive(string $slug): bool
    {
        return in_array(Format::Live, self::forSlug($slug), true);
    }
}

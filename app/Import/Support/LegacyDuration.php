<?php

declare(strict_types=1);

namespace App\Import\Support;

/**
 * Reads the free-text ACF `duration` field ("90 Minutes", "60 minutes",
 * "75 Minutes ") down to an integer of minutes.
 *
 * The field is author-typed, so it is read tolerantly: the first run of digits
 * is the duration; anything with no number (an empty value, or a stray
 * "publish") yields null, which downstream treats as "unknown" rather than
 * guessing. Credits then follow from CreditHours, never from a typed figure.
 */
final class LegacyDuration
{
    public static function minutes(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (preg_match('/\d+/', $raw, $m) !== 1) {
            return null;
        }

        $minutes = (int) $m[0];

        return $minutes > 0 ? $minutes : null;
    }
}

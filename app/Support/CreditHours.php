<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long a session has to run to be worth a credit.
 *
 * The client's rule, given 2026-09-14 and the same for every accrediting body:
 * **30 minutes of content is half a credit.** A 90-minute webinar is worth 1.5.
 *
 * It lives here rather than in a column because it is a rule, not a fact about
 * any one webinar — §12 has credit rules "prescribed and not negotiable", and
 * the thing that makes them safe is that nobody types them in. Set a webinar's
 * duration and its credits follow; there is no second place for the two to
 * disagree.
 *
 * **Complete blocks only.** A 45-minute session is worth 0.5, not 0.75: the
 * rule counts half-hours of content, and rounding the other way would have the
 * business awarding credit for time it did not deliver. That is the direction
 * an audit is unforgiving about. Sessions here run 60, 90 or 120 minutes, so
 * the case is rare — but it is a decision rather than an accident.
 */
final class CreditHours
{
    /** Minutes of content per half credit. */
    private const MINUTES_PER_BLOCK = 30;

    private const CREDITS_PER_BLOCK = 0.5;

    /**
     * What a session of this length earns, as a decimal(4,2) string.
     *
     * A string rather than a float for the same reason money is integer cents
     * (§4 rule 6): this ends up printed on a compliance document, and 1.5 must
     * not arrive as 1.4999999.
     */
    public static function forMinutes(?int $minutes): string
    {
        if ($minutes === null || $minutes < self::MINUTES_PER_BLOCK) {
            return '0.00';
        }

        $blocks = intdiv($minutes, self::MINUTES_PER_BLOCK);

        return number_format($blocks * self::CREDITS_PER_BLOCK, 2, '.', '');
    }

    /** How the rule reads on screen, so nobody has to be told it separately. */
    public static function describeRule(): string
    {
        return '30 minutes of content earns 0.5 credits, counted in complete half-hours.';
    }

    /** The shortest session worth anything at all. */
    public static function minimumMinutes(): int
    {
        return self::MINUTES_PER_BLOCK;
    }
}

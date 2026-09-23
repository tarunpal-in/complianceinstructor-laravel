<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use App\Models\Webinar;

/**
 * When somebody has earned their credits.
 *
 * The client's rules, agreed 2026-09-23:
 *
 *  - **Live.** Anyone who bought a purchase option including the live format is
 *    certified automatically, 24 hours after the session date. No attendance
 *    threshold, and no human in the loop.
 *  - **Recorded.** They must have watched the material through, give or take
 *    five to ten minutes — and the instruction was explicit that this should be
 *    generous rather than "rigid and reluctant to issue".
 *
 * Both numbers are settings rather than constants. §12 has accreditation rules
 * "prescribed by HRCI/SHRM and not negotiable" and warns to keep thresholds
 * configurable per body, never hardcoded — so when a body comes back with
 * something different it is a value the client changes, not a deploy.
 *
 * The live rule sounds lax and is not. It is the client's own reading of what
 * their bodies require, and it matches how the business already works: the live
 * option is what is sold, and attending is the customer's affair. Nothing here
 * invents a stricter rule than the one the business is accountable for.
 */
final class CompletionRules
{
    public const LIVE_DELAY_KEY = 'certificates.live_delay_hours';

    public const WATCH_TOLERANCE_KEY = 'certificates.watch_tolerance_minutes';

    public const DEFAULT_LIVE_DELAY_HOURS = 24;

    public const DEFAULT_WATCH_TOLERANCE_MINUTES = 10;

    /** How long after a live session its certificates are issued. */
    public static function liveDelayHours(): int
    {
        return max(0, (int) Setting::get(self::LIVE_DELAY_KEY, self::DEFAULT_LIVE_DELAY_HOURS));
    }

    /**
     * How much of a recording may be left unwatched and still count.
     *
     * Deliberately forgiving. Somebody who stops the video over the closing
     * remarks has watched the session, and a rule that refuses them a
     * certificate produces a support ticket the business resolves by hand
     * anyway — the same outcome, slower and more annoying for everyone.
     */
    public static function watchToleranceMinutes(): int
    {
        return max(0, (int) Setting::get(self::WATCH_TOLERANCE_KEY, self::DEFAULT_WATCH_TOLERANCE_MINUTES));
    }

    /**
     * How far into a recording counts as having watched it.
     *
     * Never below a minute. Without that floor the tolerance would swallow a
     * short session whole and turn "pressed play" into "completed" — a
     * fifteen-minute webinar against a ten-minute tolerance would certify
     * somebody five minutes in.
     */
    public static function watchedSecondsRequired(Webinar $webinar): int
    {
        $total = (int) $webinar->duration_minutes * 60;

        return max(60, $total - (self::watchToleranceMinutes() * 60));
    }

    /** Whether this much of a recording is enough. */
    public static function hasWatchedEnough(Webinar $webinar, int $furthestSeconds): bool
    {
        return $furthestSeconds >= self::watchedSecondsRequired($webinar);
    }

    /** When a live session's certificates become due. */
    public static function liveCertificatesDueAfter(Webinar $webinar): ?\Carbon\CarbonInterface
    {
        return $webinar->scheduled_at?->copy()->addHours(self::liveDelayHours());
    }

    public static function describeLiveRule(): string
    {
        return sprintf(
            'Everyone holding live access is certified automatically %d hours after the session.',
            self::liveDelayHours(),
        );
    }

    public static function describeWatchRule(): string
    {
        return sprintf(
            'Watching to within %d minutes of the end counts as completing it.',
            self::watchToleranceMinutes(),
        );
    }
}

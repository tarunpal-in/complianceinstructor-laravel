<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Accreditation;
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
 * Both numbers are settings rather than constants, and either can be overridden
 * on an individual accrediting body — §12 has their rules "prescribed by
 * HRCI/SHRM and not negotiable" and warns to keep thresholds configurable per
 * body, never hardcoded.
 *
 * Pass an Accreditation and you get that body's rule; pass nothing and you get
 * the global default. The methods take it optionally rather than requiring it
 * because "did they finish the video" is a fact about watching and has one
 * answer, while "have they earned this body's credit" has as many answers as
 * there are bodies on the webinar.
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
    public static function watchedSecondsRequired(Webinar $webinar, ?Accreditation $accreditation = null): int
    {
        $total = (int) $webinar->duration_minutes * 60;
        $tolerance = $accreditation?->watchToleranceMinutes() ?? self::watchToleranceMinutes();

        return max(60, $total - ($tolerance * 60));
    }

    /** Whether this much of a recording is enough — for a body, or in general. */
    public static function hasWatchedEnough(Webinar $webinar, int $furthestSeconds, ?Accreditation $accreditation = null): bool
    {
        return $furthestSeconds >= self::watchedSecondsRequired($webinar, $accreditation);
    }

    /** When a live session's certificates become due — for a body, or in general. */
    public static function liveCertificatesDueAfter(Webinar $webinar, ?Accreditation $accreditation = null): ?\Carbon\CarbonInterface
    {
        $hours = $accreditation?->liveDelayHours() ?? self::liveDelayHours();

        return $webinar->scheduled_at?->copy()->addHours($hours);
    }

    /**
     * Whether this learner has earned this body's certificate for this webinar.
     *
     * The single question automatic issuing asks, and the only place the two
     * routes to a certificate are weighed together. Either satisfies it: a live
     * attendee once the body's delay has passed, or somebody who has watched
     * the recording to within the body's tolerance.
     *
     * The facts are passed in rather than looked up, so a sweep over a hundred
     * learners resolves each learner once instead of once per accrediting body.
     */
    public static function qualifiesFor(
        Webinar $webinar,
        Accreditation $accreditation,
        bool $holdsLiveAccess,
        ?int $furthestSeconds = null,
    ): bool {
        if ($holdsLiveAccess && self::liveDelayHasElapsed($webinar, $accreditation)) {
            return true;
        }

        return $furthestSeconds !== null
            && self::hasWatchedEnough($webinar, $furthestSeconds, $accreditation);
    }

    /** Whether enough time has passed since a live session for this body. */
    public static function liveDelayHasElapsed(Webinar $webinar, ?Accreditation $accreditation = null): bool
    {
        $due = self::liveCertificatesDueAfter($webinar, $accreditation);

        // A webinar with no date has no live session to have attended, so the
        // live route cannot be what earned it.
        return $due !== null && $due->isPast();
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
            'Watching to within %d minutes of the end counts as completing it, unless an accrediting body sets its own.',
            self::watchToleranceMinutes(),
        );
    }
}

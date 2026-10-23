<?php

declare(strict_types=1);

namespace App\Import\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The ACF `date` (always YYYYMMDD) and the free-text `time_from`
 * ("10 AM PT | 01 PM ET", or "Available All Day") → one scheduled instant.
 *
 * Every session is a US event quoted Pacific-first, so the Pacific time is the
 * one read and the zone is always America/Los_Angeles — Webinar::scheduledTimeLabel()
 * then renders both PT and ET from that single instant. When no Pacific time is
 * stated (a recording, "Available All Day"), the date is kept at the house 10 AM
 * PT so the record still carries its original session day; a date that will not
 * parse yields null rather than a guess.
 */
final class LegacySchedule
{
    public const ZONE = 'America/Los_Angeles';

    private const DEFAULT_HOUR = 10;

    /**
     * @return array{at: ?Carbon, timezone: string}
     */
    public static function parse(?string $date, ?string $timeFrom): array
    {
        $date = trim((string) $date);

        if (preg_match('/^\d{8}$/', $date) !== 1) {
            return ['at' => null, 'timezone' => self::ZONE];
        }

        [$hour, $minute] = self::pacificTime($timeFrom);

        try {
            $at = Carbon::create(
                (int) substr($date, 0, 4),
                (int) substr($date, 4, 2),
                (int) substr($date, 6, 2),
                $hour,
                $minute,
                0,
                self::ZONE,
            );
        } catch (Throwable) {
            return ['at' => null, 'timezone' => self::ZONE];
        }

        // Stored as a UTC instant (the display zone is kept separately), so the
        // offset survives the database round-trip — Eloquent formats a datetime
        // in its own zone on write and reads it back in the app zone.
        return ['at' => $at->setTimezone('UTC'), 'timezone' => self::ZONE];
    }

    /**
     * The Pacific hour and minute from the time string, or the house default.
     *
     * @return array{0: int, 1: int}
     */
    private static function pacificTime(?string $raw): array
    {
        if ($raw !== null && preg_match('/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\s*PT/i', $raw, $m) === 1) {
            $hour = (int) $m[1];
            $minute = $m[2] !== '' ? (int) $m[2] : 0;
            $meridiem = strtoupper($m[3]);

            if ($meridiem === 'PM' && $hour < 12) {
                $hour += 12;
            }

            if ($meridiem === 'AM' && $hour === 12) {
                $hour = 0;
            }

            return [$hour, $minute];
        }

        return [self::DEFAULT_HOUR, 0];
    }
}

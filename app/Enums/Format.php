<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four delivery formats a webinar sells in (CLAUDE.md §1).
 *
 * A backed enum rather than loose strings, per §4 rule 5 — the predecessor lost
 * an entire membership quota check to 'E_transcript' vs 'E_transcripts', and
 * §14 #12 bans loose string comparison for authorization or quota outright.
 *
 * This is the value that ties `offering_format`, `webinar_assets`,
 * `entitlements` and `plan_entitlements` together. One spelling, in one place.
 */
enum Format: string
{
    case Live = 'live';
    case Recorded = 'recorded';
    case Transcript = 'transcript';
    case Download = 'download';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live Webinar',
            self::Recorded => 'Recorded',
            self::Transcript => 'Transcript (PDF)',
            self::Download => 'Download',
        };
    }

    /**
     * The label for a countable quantity — "4 Live Webinars".
     *
     * Spelled out rather than run through Str::plural(), which pluralises the
     * last word: "Transcript (PDF)" would come back as "Transcript (PDFS)".
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Live => 'Live Webinars',
            self::Recorded => 'Recordings',
            self::Transcript => 'Transcripts',
            self::Download => 'Downloads',
        };
    }

    /**
     * Key of the site-wide description shown in the purchase panel's tooltip.
     * The text is one setting per format, written once for the whole catalogue;
     * a bundle composes its tooltip from its constituent formats.
     */
    public function descriptionSettingKey(): string
    {
        return "formats.{$this->value}.description";
    }

    /**
     * The fallback used until the client writes their own in Settings.
     */
    public function defaultDescription(): string
    {
        return match ($this) {
            self::Live => 'Attend the session live on the scheduled date. Joining instructions are sent before it starts.',
            self::Recorded => 'Watch a recording of the session on demand, available after the live class.',
            self::Transcript => 'A written PDF transcript of the session, delivered after the live class.',
            self::Download => 'Downloadable course materials to keep.',
        };
    }

    /**
     * Whether buying this format grants access to something released on a
     * schedule rather than immediately — which is what `webinar_assets`
     * `available_from` governs.
     */
    public function isScheduled(): bool
    {
        return $this === self::Live;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $format): array => [$format->value => $format->label()])
            ->all();
    }
}

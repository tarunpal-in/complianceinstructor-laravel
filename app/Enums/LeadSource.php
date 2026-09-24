<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a lead came from — §8's "lead source attribution".
 *
 * A backed enum rather than a free string (§4 rule 5), because this is the
 * column the funnel report groups by, and §14 #12 is the predecessor losing a
 * whole quota check to `'E_transcript'` against `'E_transcripts'`. A report
 * quietly splitting "quiz" from "Quiz" into two rows is the same failure,
 * costing a decision instead of an allowance.
 *
 * Which *particular* quiz or asset produced it is a label on the row, not a
 * foreign key — see the table comment. The quiz engine is P7 and does not exist
 * yet, so a polymorphic key here would point at nothing and commit us to a
 * shape nobody has specified.
 */
enum LeadSource: string
{
    /** §6's quiz engine: answered a quiz and left their details for the result. */
    case Quiz = 'quiz';

    /** Traded their details for a gated asset — a guide, a checklist. */
    case Download = 'download';

    /** Asked about a specific webinar without buying a seat. */
    case Webinar = 'webinar';

    /** Came through a contact or enquiry form. */
    case Contact = 'contact';

    /** Loaded from a list: a conference badge scan, a bought list, a spreadsheet. */
    case Import = 'import';

    /** Typed in by staff — a phone call, a reply to an email. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Quiz => 'Quiz',
            self::Download => 'Downloaded something',
            self::Webinar => 'Webinar enquiry',
            self::Contact => 'Contact form',
            self::Import => 'Imported list',
            self::Manual => 'Added by staff',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Quiz => 'info',
            self::Download => 'primary',
            self::Webinar => 'success',
            self::Contact => 'warning',
            self::Import, self::Manual => 'gray',
        };
    }

    /**
     * Whether the person themselves asked to be here.
     *
     * An imported list did not opt in to anything, and a business selling to
     * compliance professionals should be able to tell the difference on sight
     * before anybody emails it.
     */
    public function isSelfSubmitted(): bool
    {
        return $this !== self::Import && $this !== self::Manual;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

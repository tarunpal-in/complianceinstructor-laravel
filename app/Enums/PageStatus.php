<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a marketing page is live — CLAUDE.md §5.6.
 *
 * A backed enum rather than loose strings (§4 rule 5). Scheduled is a state of
 * its own rather than "published with a future date", because the difference
 * matters to the person looking at the list: one of them is a mistake if the
 * date was wrong, and the other is not.
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'warning',
            self::Published => 'success',
        };
    }

    /**
     * Whether a page in this state may be served to the public.
     *
     * Scheduled says no on its own — the date decides, and Page::isLive() is
     * where the two are put together. §14 #2 is the reason this is one method
     * rather than a condition written out wherever a page is fetched.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
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

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

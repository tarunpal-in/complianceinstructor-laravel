<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Webinar lifecycle (CLAUDE.md §5.3).
 *
 * `scheduled`, `live` and `completed` are all publicly visible — a finished
 * webinar still sells its recording and transcript, which is most of the
 * catalogue's long tail. Only `draft` and `archived` are hidden.
 *
 * Moving out of `draft` is a gated action, not a dropdown: see
 * Webinar::publishableProblems(). A webinar with no active offering renders in
 * the catalogue and then fails at add-to-cart, because §5.3 makes `offerings`
 * the only place a price is ever read from.
 */
enum WebinarStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Live => 'Live now',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'info',
            self::Live => 'danger',
            self::Completed => 'success',
            self::Archived => 'warning',
        };
    }

    /**
     * Whether the public catalogue shows it.
     */
    public function isPublic(): bool
    {
        return in_array($this, [self::Scheduled, self::Live, self::Completed], true);
    }

    /** @return array<int, string> */
    public static function publicValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isPublic()),
        );
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}

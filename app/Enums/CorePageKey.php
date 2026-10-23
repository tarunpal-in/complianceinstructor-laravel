<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fixed set of editable "core" marketing pages (CLAUDE.md §6).
 *
 * A backed enum, not loose strings, per §4 rule 5 — the key ties a `core_pages`
 * row to its controller, its route and the seeder, and one misspelling would
 * silently detach the content from the page it is meant to drive.
 */
enum CorePageKey: string
{
    case Speakers = 'speakers';
    case Membership = 'membership';
    case Industries = 'industries';
    case Contact = 'contact';

    public function label(): string
    {
        return match ($this) {
            self::Speakers => 'Speakers',
            self::Membership => 'Membership',
            self::Industries => 'Industries',
            self::Contact => 'Contact',
        };
    }

    /** The public path this page renders at — the hero's canonical home. */
    public function path(): string
    {
        return match ($this) {
            self::Speakers => '/speakers',
            self::Membership => '/membership',
            self::Industries => '/industries',
            self::Contact => '/contact',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

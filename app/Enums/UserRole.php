<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four admin roles from CLAUDE.md §8 improvement #3.
 *
 * A backed enum rather than loose strings, per §4 rule 5 — the predecessor lost
 * an entire membership quota check to 'E_transcript' vs 'E_transcripts', and §14
 * #12 bans loose string comparison for authorization outright.
 *
 * Named UserRole, not Role, to stay unambiguous next to Spatie's Role model.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case ContentEditor = 'content_editor';
    case Finance = 'finance';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::ContentEditor => 'Content Editor',
            self::Finance => 'Finance',
            self::Support => 'Support',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access, including roles, permissions and settings.',
            self::ContentEditor => 'Catalogue, pages, blog and media. No access to money.',
            self::Finance => 'Orders, payments, refunds, coupons and reporting.',
            self::Support => 'Learners, entitlements and order lookup. Read-mostly.',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}

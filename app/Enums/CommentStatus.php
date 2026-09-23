<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a blog comment stands.
 *
 * Nothing reaches a reader until somebody says so. §13 cut the forum first
 * precisely because unmoderated discussion is "highest moderation burden", and
 * a comment box on a compliance site collects SEO spam within days of going
 * up — so the default is Pending and the work is deciding, not cleaning up.
 *
 * Spam and Rejected are kept apart although both hide the comment. Spam is the
 * machine's guess or a moderator agreeing with it, and it is what the filter
 * learns from; Rejected is a person deciding this particular remark does not
 * belong. Collapsing them would lose the difference between "a robot wrote
 * this" and "a customer was rude", which are not the same conversation.
 */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Spam = 'spam';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::Approved => 'Published',
            self::Spam => 'Spam',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Spam => 'danger',
            self::Rejected => 'gray',
        };
    }

    /** Whether a reader sees it. */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }

    /** Whether it is still somebody's job to look at. */
    public function needsReview(): bool
    {
        return $this === self::Pending;
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

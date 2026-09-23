<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A first pass at whether a comment is spam.
 *
 * Deliberately crude, and deliberately not the last word: everything it catches
 * lands in the Spam tab where a moderator can disagree, and everything it misses
 * waits in the queue anyway because nothing is published unreviewed. The job
 * here is to keep the queue readable, not to be right.
 *
 * No third-party service. Akismet and friends mean sending every visitor's
 * comment, name, email and IP to somebody else's server, which for a site whose
 * customers are compliance professionals is a conversation the client should
 * have deliberately rather than inherit from a package choice.
 *
 * The heuristics are the ones that actually fire on a low-traffic B2B blog:
 * link stuffing, the handful of pharma and casino terms that make up most
 * drive-by spam, and a comment that is mostly URL. Anything cleverer is
 * guessing at traffic nobody has seen yet.
 */
final class CommentSpamCheck
{
    /** More than this many links reads as a link farm rather than a remark. */
    private const LINK_LIMIT = 2;

    /** Below this, a "comment" is a link with a word attached. */
    private const MIN_WORDS = 3;

    /**
     * The usual drive-by vocabulary.
     *
     * Matched as whole words so "cialis" catches the spam and "specialist"
     * does not — the classic false positive, and on a compliance blog
     * "specialist" appears constantly.
     *
     * @var array<int, string>
     */
    private const TERMS = [
        'viagra', 'cialis', 'casino', 'porn', 'crypto giveaway',
        'binary options', 'payday loan', 'replica watches', 'seo services',
    ];

    /**
     * Why this looks like spam, or null if it does not.
     */
    public static function reason(string $body, string $authorName): ?string
    {
        $text = Str::lower(strip_tags($body));

        $links = preg_match_all('#https?://#i', $body);

        if ($links > self::LINK_LIMIT) {
            return sprintf('Contains %d links.', $links);
        }

        if (str_word_count($text) < self::MIN_WORDS && $links > 0) {
            return 'Barely any text around a link.';
        }

        foreach (self::TERMS as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/i', $text) === 1) {
                return sprintf('Mentions "%s".', $term);
            }
        }

        // A name that is a URL is a robot filling a form, not somebody typing.
        if (preg_match('#https?://#i', $authorName) === 1) {
            return 'The name is a web address.';
        }

        return null;
    }

    public static function looksLikeSpam(string $body, string $authorName): bool
    {
        return self::reason($body, $authorName) !== null;
    }
}

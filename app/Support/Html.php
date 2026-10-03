<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Admin-authored rich text, made safe to print.
 *
 * **The only way rich text reaches a page.** RichTextSanitisationTest fails the
 * build on any `{!! … !!}` in a view that is not either escaped or routed through
 * here, with the handful of genuinely-static exceptions listed by name.
 *
 * §9's table already carries one of these: stored XSS through the speaker social
 * URLs, where "the form validated but the reader didn't". The blog body and the
 * page-builder rich-text block had the same shape, and the comment on the block
 * said the quiet part out loud — that what arrives is "a small, known set of tags
 * because the toolbar is deliberately short". That describes what the editor
 * *offers*. The column holds whatever was pasted into it, seeded into it,
 * imported into it, or written to it by hand.
 *
 * Author-supplied rather than visitor-supplied, so it takes a Content Editor
 * account or a compromised one. That lowers the likelihood and not the impact:
 * a payload in an article body runs for every visitor who reads it.
 *
 * **Why an allowlist and not escaping.** `e()` is the right answer for a comment
 * body, which is plain text — and that is exactly what the comments partial does.
 * An article body is meant to carry headings, lists, links and tables, so
 * escaping it would print the markup instead of rendering it. The only safe way
 * to keep formatting is to decide which tags may survive and drop the rest.
 *
 * **Why at render and not only on save.** Sanitising on save is destructive: an
 * allowlist that turns out to be too narrow has already eaten somebody's article
 * by the time anybody notices, with no copy to restore from. Sanitising here
 * leaves the stored value alone, protects every row that is already in the
 * database without a backfill, and — the point the social-URL hole made — is
 * safe whatever route the value took to get there.
 */
final class Html
{
    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * Rich text with everything dangerous removed.
     *
     * Returns a string rather than null so a view can print it unconditionally.
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return self::sanitizer()->sanitize($html);
    }

    /**
     * Whether sanitising would change this value.
     *
     * For the admin: a screen can warn an author that something in what they
     * pasted will not be published, which is better than silently dropping it
     * and leaving them to wonder where the embed went.
     */
    public static function isClean(?string $html): bool
    {
        return self::sanitize($html) === ($html ?? '');
    }

    /**
     * The allowlist.
     *
     * Built on Symfony's `allowSafeElements()` — the W3C-derived set — rather
     * than a hand-written list, because the hand-written one is always missing
     * the element somebody needed and always allowing the attribute nobody
     * thought about.
     *
     * Three deliberate narrowings on top of it:
     *
     * `h1` is dropped to `h2`. Every page already has one `h1` — the article
     * title — and a second is an accessibility and SEO regression rather than a
     * security one, but this is the one place that can enforce it.
     *
     * **No `iframe`.** It is the element that turns an article into a host for
     * someone else's page. If video embeds are ever wanted, that is a deliberate
     * change here with a host allowlist, not something that arrives by paste.
     *
     * **Link schemes are http, https and mailto only.** `javascript:` is the
     * obvious one; `data:` is the one people forget, and a `data:text/html` link
     * is a same-origin page under the author's control.
     */
    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()

                // Internal links are how one article points at another, and §3
                // puts this business's living on search.
                ->allowRelativeLinks()
                ->allowRelativeMedias()

                ->allowLinkSchemes(['http', 'https', 'mailto'])
                // Images only, and not `data:` — an inline document is a page,
                // not a picture.
                ->allowMediaSchemes(['http', 'https'])

                // What the editor's toolbar actually produces, so a legitimate
                // article survives intact.
                ->allowAttribute('href', ['a'])
                ->allowAttribute('title', ['a', 'abbr', 'img'])
                ->allowAttribute('target', ['a'])
                ->allowAttribute('rel', ['a'])
                ->allowAttribute('src', ['img'])
                ->allowAttribute('alt', ['img'])
                ->allowAttribute('width', ['img'])
                ->allowAttribute('height', ['img'])
                ->allowAttribute('colspan', ['td', 'th'])
                ->allowAttribute('rowspan', ['td', 'th'])

                // A second h1 competes with the article title.
                ->dropElement('h1')

                // Named rather than left to the default list, so the intent is
                // readable and a future change to Symfony's defaults cannot
                // quietly let one back in.
                ->dropElement('iframe')
                ->dropElement('frame')
                ->dropElement('frameset')
                ->dropElement('object')
                ->dropElement('embed')
                ->dropElement('applet')
                ->dropElement('form')
                ->dropElement('input')
                ->dropElement('button')
                ->dropElement('select')
                ->dropElement('textarea')
                ->dropElement('script')
                ->dropElement('style')
                ->dropElement('link')
                ->dropElement('meta')
                ->dropElement('base')

                /*
                 * No length cap.
                 *
                 * Symfony's default `maxInputLength` is 20,000 characters and
                 * silently truncates past it. A long compliance article with a
                 * table of accreditation bodies passes that easily, and an
                 * article cut off two thirds through — with no error anywhere —
                 * is a worse failure than the one this class exists to prevent.
                 */
                ->withMaxInputLength(-1),
        );
    }
}

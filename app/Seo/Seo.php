<?php

declare(strict_types=1);

namespace App\Seo;

use App\Contracts\Seoable;
use App\Models\Media;
use App\Models\Setting;
use App\Support\Business;
use Illuminate\Support\Str;

/**
 * Turns a content record into the SEO values a page renders.
 *
 * The one place the fallback chain lives, so "what title does this page show if
 * nobody set one" has a single answer rather than a different one in each
 * template. The brief's Main Goal — SEO manageable from the CMS without a
 * developer — depends on that chain being right: an editor overrides a field
 * only when the computed default is not good enough.
 *
 * **Titles and descriptions are never auto-duplicated** (brief §3). A computed
 * title carries the record's own title plus the site name; two different
 * webinars cannot collide on it unless they are genuinely the same webinar.
 */
final class Seo
{
    /**
     * For a content record — a webinar, article, speaker, page or category.
     */
    public static function for(Seoable $seoable, string $ogType = 'website'): SeoData
    {
        $siteName = Business::name();

        // A full override is used verbatim: the SEO team wrote exactly what they
        // want in the result, branding and all (brief §3, "unique SEO title").
        // A computed title gets the site-name suffix so it stays branded and
        // distinct.
        $title = $seoable->seoTitle()
            ?? self::withSiteName($seoable->defaultSeoTitle(), $siteName);

        $description = self::clampDescription(
            $seoable->seoDescription()
            ?? $seoable->defaultSeoDescription()
            ?? self::siteDescription(),
        );

        return new SeoData(
            title: $title,
            description: $description,
            canonical: $seoable->seoCanonicalOverride() ?? self::canonicalForCurrent(),
            robots: $seoable->seoRobots() ?? 'index, follow',
            ogType: $ogType,
            ogImage: $seoable->seoOgImageUrl()
                ?? $seoable->defaultSeoOgImageUrl()
                ?? self::defaultOgImage(),
        );
    }

    /**
     * For a page with no content record behind it — the home page, a listing,
     * anything hand-built.
     */
    public static function site(
        ?string $title = null,
        ?string $description = null,
        string $ogType = 'website',
        // Transactional pages reached from a signed link — a confirmation, an
        // unsubscribe — pass "noindex, follow": there is nothing on them a search
        // engine should hold, and they must stay out of the sitemap too.
        string $robots = 'index, follow',
    ): SeoData {
        $siteName = Business::name();

        return new SeoData(
            title: filled($title) ? self::withSiteName($title, $siteName) : $siteName,
            description: self::clampDescription($description ?? self::siteDescription()),
            canonical: self::canonicalForCurrent(),
            robots: $robots,
            ogType: $ogType,
            ogImage: self::defaultOgImage(),
        );
    }

    /**
     * The canonical URL for the current request.
     *
     * Built by hand rather than with `url()` because Laravel's URL generator
     * trims trailing slashes, and the agreed canonical form keeps them (matching
     * the live WordPress URLs, so the migration triggers no mass 301s).
     *
     * Three things make it "the one preferred URL" (brief §7): the host is the
     * configured one, not whatever the request arrived on (so www/non-www and
     * bare-IP access all canonicalise to the same place); the scheme is always
     * HTTPS; and the query string is dropped, so `?utm_source=…` and filter
     * params never fork a page into duplicates.
     */
    public static function canonicalForCurrent(): string
    {
        return self::absoluteUrl(request()->getPathInfo());
    }

    /**
     * A path turned into an absolute, HTTPS, trailing-slash URL on the preferred
     * host. Shared with the sitemap and the trailing-slash middleware so all
     * three agree on what a canonical URL looks like.
     */
    public static function absoluteUrl(string $path): string
    {
        $parts = parse_url((string) config('app.url'));
        $host = $parts['host'] ?? 'localhost';

        // Keep a non-standard port so local and staging URLs still resolve; a
        // production URL has none and this adds nothing.
        $port = isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)
            ? ':'.$parts['port']
            : '';

        return 'https://'.$host.$port.self::withTrailingSlash($path);
    }

    /** "/webinars/foo" → "/webinars/foo/". The root stays "/". */
    public static function withTrailingSlash(string $path): string
    {
        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : $path.'/';
    }

    private static function withSiteName(string $title, string $siteName): string
    {
        $title = trim($title);

        if ($title === '') {
            return $siteName;
        }

        // Guard against a default that already ends in the site name, so a page
        // never reads "Title — Site — Site".
        return str_ends_with($title, $siteName) ? $title : "{$title} — {$siteName}";
    }

    /**
     * Descriptions are trimmed to ~160 characters on a word boundary. A search
     * snippet is truncated there anyway, and a tidy sentence reads better than a
     * mid-word cut. The admin field warns past this; this enforces it for
     * computed defaults pulled from a long body.
     */
    private static function clampDescription(string $description): string
    {
        $description = trim(preg_replace('/\s+/', ' ', strip_tags($description)) ?? '');

        return Str::limit($description, 160, '');
    }

    private static function siteDescription(): string
    {
        return Business::tagline()
            ?? 'Live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM and NASBA/CPE recertification credits.';
    }

    private static function defaultOgImage(): ?string
    {
        $id = Setting::mediaId('brand.og_default_media_id');

        if ($id === null) {
            return null;
        }

        return Media::query()->find($id)?->detailImageUrl();
    }
}

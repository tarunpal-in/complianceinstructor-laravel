<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\CorePage;
use App\Models\Page;
use App\Models\Post;
use App\Models\Webinar;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Spatie\Sitemap\Sitemap as SpatieSitemap;
use Spatie\Sitemap\Tags\Url;

/**
 * Builds the XML sitemap — CLAUDE.md §5.6, client SEO brief §6.
 *
 * Built from the content records rather than by crawling the site: it is faster,
 * and it can honour the things a crawler could not easily know — a page the SEO
 * team set to noindex, a draft, an archived webinar. The brief's "do not
 * include" list (404, redirected, noindex, duplicate URLs) falls out of that: we
 * only ever add live, indexable records, each at its one canonical URL, so a
 * redirected old path or a hidden page simply never appears.
 *
 * Cached, and the cache is dropped whenever a webinar, article or page is saved
 * (see HasSeo), so it is always current without being rebuilt on every request.
 */
final class Sitemap
{
    public const CACHE_KEY = 'seo.sitemap';

    /** How long a built sitemap is served before it is rebuilt, if nothing changed it sooner. */
    private const TTL_MINUTES = 60;

    /** The rendered XML, from cache where possible. */
    public static function xml(): string
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::TTL_MINUTES),
            fn (): string => self::build()->render(),
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function build(): SpatieSitemap
    {
        $sitemap = SpatieSitemap::create();

        // The homepage, first and highest.
        $sitemap->add(Url::create(Seo::absoluteUrl('/'))->setPriority(1.0));

        Webinar::query()->public()->with('seo')->get()
            ->filter->isIndexable()
            ->each(fn (Webinar $webinar) => $sitemap->add(
                self::url(Seo::absoluteUrl('/webinars/'.$webinar->slug), $webinar->updated_at),
            ));

        Post::query()->live()->with('seo')->get()
            ->filter->isIndexable()
            ->each(fn (Post $post) => $sitemap->add(
                self::url(Seo::absoluteUrl($post->path()), $post->updated_at),
            ));

        Page::query()->live()->with('seo')->get()
            ->filter->isIndexable()
            ->each(fn (Page $page) => $sitemap->add(
                self::url(Seo::absoluteUrl($page->path()), $page->updated_at),
            ));

        // The fixed core pages (Speakers, Membership, Industries, Contact) — §6.
        // Always live; each sits at its key's own path.
        CorePage::query()->with('seo')->get()
            ->filter->isIndexable()
            ->each(fn (CorePage $page) => $sitemap->add(
                self::url(Seo::absoluteUrl($page->key->path()), $page->updated_at),
            ));

        return $sitemap;
    }

    private static function url(string $location, ?DateTimeInterface $lastModified): Url
    {
        $url = Url::create($location);

        if ($lastModified !== null) {
            $url->setLastModificationDate($lastModified);
        }

        return $url;
    }
}

<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Which paths take a trailing slash — the single decision shared by the URL
 * generator (so links are emitted with the slash) and the NormalizeUrl
 * middleware (so a bare URL that slips through still redirects to it).
 *
 * The agreed canonical form ends in a slash, matching the live WordPress site
 * (client SEO brief §7). The point of doing it in URL *generation* rather than
 * only redirecting is to remove the 301 hop on internal links: every `route()`
 * and `url()` to a content page already points at the canonical form.
 *
 * Only public content qualifies. Machinery (admin, Livewire, the API, the health
 * check, assets), files with an extension, and **signed routes** are all left
 * bare. Signed routes especially: a signature is computed over the generated URL
 * but validated against a path the framework rtrims, so a trailing slash on one
 * would never validate. The preview routes live under `/blog/preview` and
 * `/preview`, so a `preview` segment anywhere excludes the path, and the
 * generator also checks the route's middleware as a second guard.
 */
final class TrailingSlash
{
    /**
     * First path segments that are machinery or signed, never content.
     *
     * @var array<int, string>
     */
    public const EXCLUDED_FIRST_SEGMENTS = [
        'admin', 'livewire', 'api', 'horizon', 'storage', 'up',
        'build', 'vendor', 'broadcasting',
        'mailing-list', 'newsletter', 'preview',
    ];

    /** Whether this path should carry a trailing slash. */
    public static function wanted(string $path): bool
    {
        $path = '/'.trim(self::pathOnly($path), '/');

        // The root is its own canonical.
        if ($path === '/') {
            return false;
        }

        // A dot in the last segment means a file — robots.txt, sitemap.xml, an
        // asset — which never takes a trailing slash.
        if (str_contains(basename($path), '.')) {
            return false;
        }

        $segments = array_map('strtolower', explode('/', trim($path, '/')));

        if (in_array($segments[0], self::EXCLUDED_FIRST_SEGMENTS, true)) {
            return false;
        }

        // Livewire 4 obfuscates its route prefix to livewire-<hash>, so the plain
        // 'livewire' entry above no longer matches it. Left unhandled, its signed
        // upload route is emitted with a trailing slash and fails signature
        // validation (a 401) — the exact failure this exclusion exists to prevent.
        if (str_starts_with($segments[0], 'livewire')) {
            return false;
        }

        // A signed preview route can sit deeper than the first segment
        // (/blog/preview/{post}). A trailing slash would break its signature.
        return ! in_array('preview', $segments, true);
    }

    /** The path portion of a value that might be a full URL or carry a query. */
    private static function pathOnly(string $value): string
    {
        return (string) (parse_url($value, PHP_URL_PATH) ?: '/');
    }
}

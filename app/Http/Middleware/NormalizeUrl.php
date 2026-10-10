<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One URL form for every public page — the trailing-slash side of brief §7.
 *
 * The client's URLs end in a slash (`/webinars/fmla-compliance-2026/`), matching
 * their live WordPress site. Laravel resolves both forms to the same route (it
 * trims the path before matching), so this does not make the trailing form
 * *work* — it makes it the *only* form, 301-ing the bare version to it so a page
 * is never reachable at two URLs that search engines would treat as duplicates.
 *
 * **Public content only.** The admin panel, Livewire's own endpoints, the API,
 * the health check and anything with a file extension (robots.txt, sitemap.xml,
 * an asset) are left exactly as they are — a trailing slash on `/admin/login/`
 * or `/livewire/update/` would break them. The redirect is also GET/HEAD only:
 * a form POST must reach its handler, not bounce to a slashed URL and lose its
 * body.
 *
 * Two framework quirks shape the implementation. `Request::getPathInfo()`
 * normalises away a trailing slash, so the raw request URI is parsed instead to
 * tell the two forms apart. And the URL generator trims trailing slashes off
 * everything it builds (see UrlGenerator::format), so the redirect target is
 * assembled by hand and sent with `away()`, which does not run it through the
 * generator.
 */
class NormalizeUrl
{
    /**
     * First path segments that are machinery, not content. A trailing slash on
     * any of these is wrong, so they pass through untouched. The signed and
     * POST-target routes (mailing-list, newsletter, preview) are here too: their
     * URLs are generated and validated bare, and must stay that way.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_PREFIXES = [
        'admin', 'livewire', 'api', 'horizon', 'storage', 'up',
        'build', 'vendor', 'broadcasting',
        'mailing-list', 'newsletter', 'preview',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Off in the test HTTP client — see config/seo.php for why. On
        // everywhere else.
        if (! config('seo.trailing_slash_redirect')) {
            return $next($request);
        }

        $path = (string) (parse_url($request->getRequestUri(), PHP_URL_PATH) ?: '/');

        if (! $this->shouldNormalise($request, $path)) {
            return $next($request);
        }

        $query = parse_url($request->getRequestUri(), PHP_URL_QUERY);

        $target = $request->getSchemeAndHttpHost()
            .rtrim($path, '/').'/'
            .($query !== null && $query !== '' ? '?'.$query : '');

        // away() sends the URL verbatim. 301, not 302: the trailing-slash form is
        // permanent, and a temporary redirect would not pass link equity to it.
        return redirect()->away($target, 301);
    }

    private function shouldNormalise(Request $request, string $path): bool
    {
        if (! $request->isMethodSafe()) {
            return false;
        }

        // The root is its own canonical, and anything already slashed is done.
        if ($path === '/' || str_ends_with($path, '/')) {
            return false;
        }

        $firstSegment = Str::of($path)->trim('/')->before('/')->lower()->value();

        if (in_array($firstSegment, self::EXCLUDED_PREFIXES, true)) {
            return false;
        }

        // A dot in the last segment means a file — robots.txt, sitemap.xml,
        // favicon.ico — which never takes a trailing slash.
        return ! str_contains(basename($path), '.');
    }
}

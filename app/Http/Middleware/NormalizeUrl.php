<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Seo\TrailingSlash;
use Closure;
use Illuminate\Http\Request;
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
 * Now that the URL generator emits trailing-slash URLs for content
 * (TrailingSlashUrlGenerator), internal links already point at the canonical
 * form and rarely reach here. This stays as the safety net for a bare URL from
 * outside — an old link, a typed address, a crawler following a legacy path.
 *
 * Two framework quirks shape the implementation. `Request::getPathInfo()`
 * normalises away a trailing slash, so the raw request URI is parsed instead to
 * tell the two forms apart. And the URL generator — even the trailing-slash one
 * — leaves a `redirect()` target to the generator, which would re-trim it, so
 * the target is assembled by hand and sent with `away()`.
 *
 * The decision about which paths take a slash is shared with the generator
 * through App\Seo\TrailingSlash, so the two can never disagree.
 */
class NormalizeUrl
{
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

        // Anything already slashed is done; everything else defers to the shared
        // decision (root, files, machinery and signed routes are all excluded).
        if (str_ends_with($path, '/')) {
            return false;
        }

        return TrailingSlash::wanted($path);
    }
}

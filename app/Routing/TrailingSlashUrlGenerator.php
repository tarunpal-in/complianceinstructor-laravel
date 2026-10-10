<?php

declare(strict_types=1);

namespace App\Routing;

use App\Seo\TrailingSlash;
use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;

/**
 * Makes `route()` and `url()` emit trailing-slash URLs for public content.
 *
 * Laravel's URL generator ends `format()` with `trim($root.$path, '/')`, which
 * strips any trailing slash, and there is no hook that survives it
 * (`formatPathUsing` runs before the trim). So the only clean way to emit the
 * canonical trailing-slash form from every link — rather than redirecting to it
 * after the fact — is to override `format()` here.
 *
 * Doing it in generation is what removes the 301 hop on internal navigation: a
 * link to a webinar already points at `/webinars/foo/`, so a visitor never
 * bounces through the bare form. NormalizeUrl stays as the safety net for bare
 * URLs that arrive from outside (an old link, a typed address).
 *
 * The slash is appended after the parent has built the URL. The query string is
 * not present at this point — `UrlGenerator::to()` and `RouteUrlGenerator::to()`
 * both strip it before calling `format()` and re-append it afterwards — so the
 * slash lands in the right place, before any `?`.
 */
class TrailingSlashUrlGenerator extends UrlGenerator
{
    /**
     * @param  string  $root
     * @param  string  $path
     * @param  Route|null  $route
     */
    public function format($root, $path, $route = null)
    {
        $formatted = parent::format($root, $path, $route);

        if (! TrailingSlash::wanted($path)) {
            return $formatted;
        }

        // Second guard for a signed route that is not caught by the path rules:
        // its signature is validated against an rtrimmed path, so a trailing
        // slash would never validate. Checked only for content paths, to keep
        // the cost off every admin and asset URL.
        if ($route instanceof Route && in_array('signed', $route->gatherMiddleware(), true)) {
            return $formatted;
        }

        return $formatted.'/';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the 301/302 register — client SEO brief §4.
 *
 * Runs before NormalizeUrl, so a moved URL redirects straight to its
 * destination in one hop rather than first being sent to its own trailing-slash
 * form and then on again. It matches on the normalised path (slash-trimmed,
 * lowercased, query dropped), so `/Old-Page/` and `/old-page` are the same move,
 * and `Redirect::destinationFor` follows any chain to the end so a visitor never
 * bounces more than once.
 *
 * GET/HEAD only: a form POST must reach its handler. The query string is carried
 * across to an internal destination, so `?utm_source=…` survives the move.
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        // The table may not exist yet on a fresh clone before migrating; a
        // missing table must not 500 every page.
        if (! $request->isMethodSafe() || ! Schema::hasTable('redirects')) {
            return $next($request);
        }

        $path = (string) (parse_url($request->getRequestUri(), PHP_URL_PATH) ?: '/');

        $destination = Redirect::destinationFor($path);

        if ($destination === null) {
            return $next($request);
        }

        [$to, $status] = $destination;

        Redirect::recordHit($path);

        return redirect()->away($this->target($to, $request), $status);
    }

    /**
     * The absolute URL to send the visitor to.
     *
     * An internal path goes through url(), which gives it the canonical
     * trailing-slash form, and keeps the request's query string. An absolute
     * destination (an off-site move) is used exactly as stored.
     */
    private function target(string $to, Request $request): string
    {
        if (! str_starts_with($to, '/')) {
            return $to;
        }

        $url = url($to);
        $query = parse_url($request->getRequestUri(), PHP_URL_QUERY);

        return $query !== null && $query !== '' ? $url.'?'.$query : $url;
    }
}

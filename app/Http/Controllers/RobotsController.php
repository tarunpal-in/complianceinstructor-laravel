<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Seo\Seo;
use Illuminate\Http\Response;

/**
 * Serves robots.txt — client SEO brief §5.
 *
 * Dynamic rather than a static file, for one reason that matters more than the
 * rest: **staging must never be indexed.** A static robots.txt copied to a
 * staging server is the classic way a half-built site ends up in Google. Off
 * production, this returns a blanket disallow; on production it allows crawling,
 * keeps the admin and other machinery out, and points at the sitemap.
 *
 * It deliberately does not block CSS or JS (brief §5) — Google needs them to
 * render the page — so only the non-content paths are disallowed.
 */
class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        return response($this->body(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function body(): string
    {
        if (! app()->isProduction()) {
            // Anything that is not production is kept out of the index whole.
            return "User-agent: *\nDisallow: /\n";
        }

        $host = rtrim(Seo::absoluteUrl('/'), '/');

        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /livewire',
            'Disallow: /mailing-list',
            'Disallow: /newsletter',
            // Everything else — content, CSS and JS included — may be crawled.
            'Allow: /',
            '',
            'Sitemap: '.$host.'/sitemap.xml',
        ];

        return implode("\n", $lines)."\n";
    }
}

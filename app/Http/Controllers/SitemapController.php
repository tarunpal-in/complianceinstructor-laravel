<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Seo\Sitemap;
use Illuminate\Http\Response;

/**
 * Serves the XML sitemap at /sitemap.xml — client SEO brief §6.
 *
 * The building and caching live in App\Seo\Sitemap; this just hands the cached
 * XML back with the right content type.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        return response(Sitemap::xml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}

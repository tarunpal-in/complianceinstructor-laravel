<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Post;

// Custom 404 (brief §4) --------------------------------------------------------------------------

it('serves the custom 404 with the things the brief asks for', function (): void {
    $response = test()->get('/no-such-page-exists')->assertNotFound();
    $html = $response->getContent();

    expect($html)
        ->toContain('404')
        ->toContain("couldn't find that page")   // a clear message
        ->toContain('role="search"')              // a search option
        ->toContain(route('home'))                // a way home
        ->toContain(route('blog.index'));         // links to key sections
});

it('keeps the 404 out of the index', function (): void {
    // The 404 status does most of this; the noindex meta is belt and braces.
    expect(test()->get('/missing')->assertNotFound()->getContent())
        ->toContain('content="noindex, follow"');
});

it('still shows the site navigation on the 404', function (): void {
    // The full public shell renders, so a lost visitor keeps the whole menu.
    expect(test()->get('/missing')->getContent())->toContain('</nav>');
});

// robots.txt (brief §5) --------------------------------------------------------------------------

it('keeps non-production out of the index entirely', function (): void {
    // The test environment stands in for staging: a blanket disallow.
    $response = test()->get('/robots.txt')->assertSuccessful();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($response->getContent())->toContain('Disallow: /')
        ->and($response->getContent())->not->toContain('Sitemap:');
});

it('on production allows crawling, blocks machinery and points at the sitemap', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $body = test()->get('/robots.txt')->assertSuccessful()->getContent();

    expect($body)
        ->toContain('Allow: /')
        ->toContain('Disallow: /admin')
        ->toContain('Sitemap:')
        ->toContain('/sitemap.xml')
        // Never block the assets Google needs to render the page.
        ->not->toContain('Disallow: /build');
});

// XML sitemap (brief §6) -------------------------------------------------------------------------

it('lists indexable content at its canonical URL', function (): void {
    $webinar = publishedWebinar();
    $post = Post::factory()->published()->create(['slug' => 'a-live-article']);
    $page = Page::factory()->published()->create(['slug' => 'about']);

    $response = test()->get('/sitemap.xml')->assertSuccessful();
    $xml = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('xml')
        ->and($xml)->toContain('/webinars/'.$webinar->slug.'/')
        ->and($xml)->toContain('/blog/a-live-article/')
        ->and($xml)->toContain('/about/')
        // The homepage, and always HTTPS.
        ->and($xml)->toContain('<loc>https://');
});

it('excludes drafts and noindex pages from the sitemap', function (): void {
    Page::factory()->create(['slug' => 'a-draft']); // draft by default
    $hidden = Page::factory()->published()->create(['slug' => 'hidden-page']);
    $hidden->seo()->create(['robots' => 'noindex, follow']);

    $xml = test()->get('/sitemap.xml')->assertSuccessful()->getContent();

    expect($xml)->not->toContain('/a-draft/')
        ->and($xml)->not->toContain('/hidden-page/');
});

/*
 * A URL that was redirected must not appear in the sitemap — it would tell Google
 * to crawl a 301. It never does, because the sitemap is built from live records
 * and a redirected old path is not one.
 */
it('does not list a path that only exists as a redirect', function (): void {
    App\Models\Redirect::factory()->create(['from_path' => '/old-url', 'to_path' => '/new-url']);

    expect(test()->get('/sitemap.xml')->getContent())->not->toContain('/old-url/');
});

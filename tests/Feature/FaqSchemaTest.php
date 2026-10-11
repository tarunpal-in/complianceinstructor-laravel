<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\PageSection;

/** The decoded JSON-LD blocks on a page. */
function ldBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn (string $j): array => json_decode($j, true) ?? [], $m[1] ?? []);
}

function faqBlock(string $html): ?array
{
    foreach (ldBlocks($html) as $block) {
        if (($block['@type'] ?? null) === 'FAQPage') {
            return $block;
        }
    }

    return null;
}

/*
 * The brief §15 is firm: FAQ schema must represent the FAQ shown on the page. So
 * it is built from the same block the page renders, and only when that block
 * has real questions and answers.
 */
it('emits FAQPage schema from a visible FAQ block', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'faq-page']);
    PageSection::factory()->for($page)->create([
        'type' => 'faq',
        'is_visible' => true,
        'data' => [
            'heading' => 'Common questions',
            'questions' => [
                ['question' => 'Do I earn credits?', 'answer' => 'Yes, HRCI and SHRM credits.'],
                ['question' => 'Is there a recording?', 'answer' => 'Every live session is recorded.'],
            ],
        ],
    ]);

    $faq = faqBlock(test()->get('/faq-page')->assertSuccessful()->getContent());

    expect($faq)->not->toBeNull()
        ->and($faq['mainEntity'])->toHaveCount(2)
        ->and($faq['mainEntity'][0]['@type'])->toBe('Question')
        ->and($faq['mainEntity'][0]['name'])->toBe('Do I earn credits?')
        ->and($faq['mainEntity'][0]['acceptedAnswer']['text'])->toBe('Yes, HRCI and SHRM credits.');
});

it('emits no FAQ schema for a page without an FAQ block', function (): void {
    Page::factory()->published()->create(['slug' => 'plain-page']);

    expect(faqBlock(test()->get('/plain-page')->getContent()))->toBeNull();
});

it('ignores empty question rows', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'half-faq']);
    PageSection::factory()->for($page)->create([
        'type' => 'faq',
        'is_visible' => true,
        'data' => [
            'questions' => [
                ['question' => 'A real one?', 'answer' => 'Yes.'],
                ['question' => '', 'answer' => ''],           // blank — skipped
                ['question' => 'Missing answer?', 'answer' => ''],
            ],
        ],
    ]);

    $faq = faqBlock(test()->get('/half-faq')->getContent());

    expect($faq['mainEntity'])->toHaveCount(1);
});

it('does not emit FAQ schema from a hidden FAQ block', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'hidden-faq']);
    PageSection::factory()->for($page)->create([
        'type' => 'faq',
        'is_visible' => false,
        'data' => ['questions' => [['question' => 'Hidden?', 'answer' => 'Not shown.']]],
    ]);

    // The schema must match what the visitor sees; a hidden block shows nothing.
    expect(faqBlock(test()->get('/hidden-faq')->getContent()))->toBeNull();
});

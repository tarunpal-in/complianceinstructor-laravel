<?php

declare(strict_types=1);

use App\Enums\PublishStatus;
use App\Models\Page;
use Database\Seeders\LegalPagesSeeder;

/*
 * The four footer policy pages (§7). Their slugs must match the footer's legal
 * links (App\Support\Navigation::footer) exactly, and they must be published so
 * those links resolve publicly rather than 404.
 */

it('publishes the four legal pages at their footer slugs, each reachable', function (string $slug, string $title, string $bodyPhrase): void {
    $this->seed(LegalPagesSeeder::class);

    $page = Page::query()->where('slug', $slug)->first();

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe($title)
        ->and($page->status)->toBe(PublishStatus::Published);

    // The catch-all /{slug} route resolves only live pages, so a 200 proves the
    // footer link works; the body phrase proves the page content, not the footer
    // link of the same name, is what rendered.
    $this->get('/'.$slug)
        ->assertSuccessful()
        ->assertSee($title)
        ->assertSee($bodyPhrase);
})->with([
    'terms' => ['terms-of-service', 'Terms of Service', 'Acceptable use'],
    'privacy' => ['privacy-policy', 'Privacy Policy', 'What we collect'],
    'refund' => ['refund-policy', 'Refund Policy', '7-day refund'],
    'cookie' => ['cookie-policy', 'Cookie Policy', 'Essential cookies'],
]);

it('is idempotent — re-running keeps exactly one page per slug', function (): void {
    $this->seed(LegalPagesSeeder::class);
    $this->seed(LegalPagesSeeder::class);

    $slugs = ['terms-of-service', 'privacy-policy', 'refund-policy', 'cookie-policy'];

    expect(Page::query()->whereIn('slug', $slugs)->count())->toBe(4)
        ->and(Page::query()->where('slug', 'terms-of-service')->first()->sections()->count())->toBe(1);
});

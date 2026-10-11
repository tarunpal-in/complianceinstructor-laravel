<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Page;
use App\Models\Post;
use App\Seo\SeoAudit;
use Database\Seeders\RolePermissionSeeder;

/** The findings under one check. */
function findingsFor(string $check): array
{
    return SeoAudit::run()[$check] ?? [];
}

it('reports a page missing its meta description', function (): void {
    // A page whose body/excerpt yields no summary has no effective description.
    Page::factory()->published()->create(['slug' => 'bare-page', 'title' => 'Bare Page']);

    $subjects = collect(findingsFor('Missing meta description'))->pluck('subject');

    expect($subjects)->toContain('Page: Bare Page — '.App\Support\Business::name());
});

it('reports a title that is too long', function (): void {
    $post = Post::factory()->published()->create();
    $post->seo()->create(['title' => str_repeat('Very Long Title ', 6)]); // > 60 chars

    expect(collect(findingsFor('Title too long')))->not->toBeEmpty();
});

it('reports a description that is too long', function (): void {
    $post = Post::factory()->published()->create();
    $post->seo()->create(['description' => str_repeat('word ', 50)]); // > 160 chars

    expect(collect(findingsFor('Description too long')))->not->toBeEmpty();
});

it('reports two pages that share a title', function (): void {
    $a = Page::factory()->published()->create(['slug' => 'one']);
    $b = Page::factory()->published()->create(['slug' => 'two']);
    $a->seo()->create(['title' => 'Identical Title']);
    $b->seo()->create(['title' => 'Identical Title']);

    expect(collect(findingsFor('Duplicate titles')))->toHaveCount(2);
});

it('lists a page hidden from search', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'hidden']);
    $page->seo()->create(['robots' => 'noindex, follow']);

    expect(collect(findingsFor('Hidden from search (noindex)')))->not->toBeEmpty();
});

it('links a finding to the record so it can be fixed', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $post = Post::factory()->published()->create();
    $post->seo()->create(['title' => str_repeat('Long ', 20)]);

    $finding = collect(findingsFor('Title too long'))->firstWhere(
        fn (App\Seo\SeoFinding $f): bool => str_contains($f->subject, 'Post'),
    );

    expect($finding?->url)->toContain('/admin/')->toContain('/edit');
});

// Access ------------------------------------------------------------------------------------------

it('keeps the dashboard to roles that edit SEO', function (): void {
    $this->seed(RolePermissionSeeder::class);

    // Content Editor owns SEO and reaches it; Finance does not.
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get('/admin/seo-health')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::Finance))->get('/admin/seo-health')->assertForbidden();
});

it('shows an all-clear when there is nothing to fix', function (): void {
    expect(SeoAudit::totalIssues())->toBe(0);

    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/seo-health')
        ->assertSuccessful()
        ->assertSee('Nothing to fix');
});

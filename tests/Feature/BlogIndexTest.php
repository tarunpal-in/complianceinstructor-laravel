<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;

it('renders the blog index with a featured lead post and grid cards', function (): void {
    $cat = PostCategory::factory()->create(['name' => 'Payroll', 'slug' => 'payroll']);

    $lead = Post::factory()->published()->in($cat)->create([
        'title' => 'Lead Payroll Article',
        'published_at' => now()->subDay(),
    ]);
    Post::factory()->published()->in($cat)->count(2)->create([
        'published_at' => now()->subWeeks(2),
    ]);

    test()->get('/blog')
        ->assertSuccessful()
        ->assertSee('From the blog')
        ->assertSee('Featured')                        // the featured lead flag
        ->assertSee('Lead Payroll Article')            // newest post leads
        ->assertSee('blog-ph--amber', escape: false);  // category-toned placeholder
});

it('falls back to the gradient placeholder when a post has no cover image', function (): void {
    $cat = PostCategory::factory()->create(['name' => 'HR compliance', 'slug' => 'hr-compliance']);
    Post::factory()->published()->in($cat)->create(['title' => 'Only HR Article']);

    // No media attached, so the HR-toned illustrated placeholder stands in.
    test()->get('/blog')
        ->assertSuccessful()
        ->assertSee('Only HR Article')
        ->assertSee('blog-ph--green', escape: false);
});

it('renders a single post with conversion elements and a styled sub-heading', function (): void {
    $cat = PostCategory::factory()->create(['name' => 'Payroll', 'slug' => 'payroll']);
    $author = User::factory()->create(['name' => 'Dana Lane']);
    $post = Post::factory()->published()->in($cat)->by($author)->create([
        'title' => 'Wage Rules 2026',
        'body' => '<h2>What changed</h2><p>The details.</p>',
    ]);

    test()->get($post->path())
        ->assertSuccessful()
        ->assertSee('Wage Rules 2026')
        ->assertSee('What changed')        // the h2 renders (now styled by .pb-prose)
        ->assertSee('Earn CEU credits')    // sidebar conversion card
        ->assertSee('Browse webinars')
        ->assertSee('See webinars')        // inline CTA
        ->assertSee('Written by')          // author byline
        ->assertSee('Dana Lane')
        ->assertSeeHtml('blog-share');     // share row
});

<?php

declare(strict_types=1);

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PostCategories\Pages\ListPostCategories;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use Database\Seeders\BlogSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Authorization ---------------------------------------------------------------

it('opens the blog to roles that may edit content', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/posts')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::ContentEditor]);

it('keeps roles without a post permission out', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/posts')
        ->assertForbidden();
})->with([UserRole::Finance, UserRole::Support]);

/** Categories are part of the blog, so they ride on the same permission. */
it('gates categories on the post permission', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/post-categories')
        ->assertSuccessful();

    $this->actingAs(userWithRole(UserRole::Support))
        ->get('/admin/post-categories')
        ->assertForbidden();
});

// Writing -----------------------------------------------------------------------

it('writes an article', function (): void {
    $author = userWithRole(UserRole::ContentEditor);
    $this->actingAs($author);

    $category = PostCategory::factory()->create();

    Livewire\Livewire::test(CreatePost::class)
        ->fillForm([
            'title' => 'FMLA in 2026',
            'slug' => 'fmla-in-2026',
            'excerpt' => 'What changed.',
            'body' => '<p>The body.</p>',
            'post_category_id' => $category->getKey(),
            'user_id' => $author->getKey(),
            'status' => PublishStatus::Draft->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $post = Post::query()->where('slug', 'fmla-in-2026')->firstOrFail();

    expect($post->category?->is($category))->toBeTrue()
        ->and($post->author?->is($author))->toBeTrue()
        ->and($post->isLive())->toBeFalse();
});

/** The same auto-resolve the rest of the site's slugs get. */
it('numbers a slug another article already holds', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Post::factory()->create(['slug' => 'fmla-in-2026']);

    Livewire\Livewire::test(CreatePost::class)
        ->fillForm([
            'title' => 'FMLA in 2026',
            'slug' => 'fmla-in-2026',
            'body' => '<p>Another take.</p>',
            'status' => PublishStatus::Draft->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::query()->where('slug', 'fmla-in-2026-2')->exists())->toBeTrue();
});

it('estimates how long an article takes to read', function (): void {
    expect(Post::factory()->ofLength(200)->make()->describeReadingTime())->toBe('1 min read')
        ->and(Post::factory()->ofLength(1400)->make()->readingMinutes())->toBe(7)
        // Never zero: something is always at least a minute.
        ->and(Post::factory()->ofLength(5)->make()->readingMinutes())->toBe(1);
});

/** An index card with nothing on it is worse than a machine-cut sentence. */
it('falls back to the opening of the body when no summary was written', function (): void {
    $post = Post::factory()->make(['excerpt' => null, 'body' => '<p>Opening sentence of the article.</p>']);

    expect($post->summary())->toBe('Opening sentence of the article.');
});

/** One tag vocabulary for the whole site, kept apart by type. */
it('tags an article out of the shared vocabulary', function (): void {
    $post = Post::factory()->create();
    $tag = Tag::findOrCreateNamed('Compliance', Tag::TYPE_POST);

    $post->tags()->attach($tag);

    expect($post->fresh()->tags->pluck('name')->all())->toBe(['Compliance'])
        ->and($tag->fresh()->posts)->toHaveCount(1)
        // And it does not leak into the media library's picker.
        ->and(Tag::query()->where('type', Tag::TYPE_MEDIA)->count())->toBe(0);
});

// Publishing ----------------------------------------------------------------------

it('will not publish an article with no body', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $post = Post::factory()->create(['body' => '<p>  </p>']);

    Livewire\Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->callAction('publish');

    expect($post->fresh()?->isLive())->toBeFalse()
        ->and($post->fresh()?->problems())->toContain('It has no body text.');
});

/** Nothing on the site links to an uncategorised article. */
it('will not publish an article with no category', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $post = Post::factory()->uncategorised()->create();

    Livewire\Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->callAction('publish');

    expect($post->fresh()?->isLive())->toBeFalse();
});

it('publishes a finished article', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $post = Post::factory()->create();

    Livewire\Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->callAction('publish');

    expect($post->fresh()?->isLive())->toBeTrue();
});

it('takes an article down and keeps the date it first went out', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $post = Post::factory()->published()->create();
    $first = $post->published_at;

    Livewire\Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->callAction('unpublish');

    $post = $post->fresh();

    expect($post?->isLive())->toBeFalse()
        ->and($post?->published_at?->toDateTimeString())->toBe($first?->toDateTimeString());
});

it('reads a future date as scheduled', function (): void {
    $post = Post::factory()->scheduled()->create();

    expect($post->derivedStatus())->toBe(PublishStatus::Scheduled)
        ->and($post->isLive())->toBeFalse()
        ->and(Post::query()->live()->count())->toBe(0);
});

it('separates what is out from what is being written', function (): void {
    $published = Post::factory()->published()->create();
    $draft = Post::factory()->create();
    $uncategorised = Post::factory()->uncategorised()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(ListPosts::class)
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$draft])
        ->set('activeTab', 'uncategorised')
        ->assertCanSeeTableRecords([$uncategorised])
        ->assertCanNotSeeTableRecords([$published]);
});

// Categories ------------------------------------------------------------------------

/**
 * Deleting a category leaves its articles uncategorised, and nothing on the
 * site links to an uncategorised article. Said before it happens.
 */
it('warns before deleting a category that has articles in it', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $category = PostCategory::factory()->create();
    Post::factory()->in($category)->create();

    Livewire\Livewire::test(ListPostCategories::class)
        ->callTableAction('delete', $category);

    expect($category->fresh()?->trashed())->toBeFalse();
});

it('deletes an empty category without complaint', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    $category = PostCategory::factory()->create();

    Livewire\Livewire::test(ListPostCategories::class)
        ->callTableAction('delete', $category);

    expect($category->fresh()?->trashed())->toBeTrue();
});

/** Losing the articles would be far worse than losing the grouping. */
it('leaves articles standing when their category goes', function (): void {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->in($category)->published()->create();

    $category->forceDelete();

    expect($post->fresh())->not->toBeNull()
        ->and($post->fresh()?->post_category_id)->toBeNull();
});

it('counts only live articles in a category', function (): void {
    $category = PostCategory::factory()->create();

    Post::factory()->in($category)->published()->count(2)->create();
    Post::factory()->in($category)->create();

    expect($category->livePostCount())->toBe(2);
});

// The public blog ----------------------------------------------------------------------

it('lists published articles', function (): void {
    $live = Post::factory()->published()->create(['title' => 'Published piece']);
    Post::factory()->create(['title' => 'Unfinished piece']);

    $this->get('/blog')
        ->assertSuccessful()
        ->assertSee('Published piece', escape: false)
        ->assertDontSee('Unfinished piece', escape: false);
});

it('filters the index by category', function (): void {
    $payroll = PostCategory::factory()->create(['name' => 'Payroll', 'slug' => 'payroll']);
    $other = PostCategory::factory()->create();

    Post::factory()->in($payroll)->published()->create(['title' => 'Payroll piece']);
    Post::factory()->in($other)->published()->create(['title' => 'Other piece']);

    $this->get('/blog?category=payroll')
        ->assertSuccessful()
        ->assertSee('Payroll piece', escape: false)
        ->assertDontSee('Other piece', escape: false);
});

it('reads an article at its address', function (): void {
    Post::factory()->published()->create([
        'slug' => 'fmla-in-2026',
        'title' => 'FMLA in 2026',
        'body' => '<p>The body of the article.</p>',
    ]);

    $this->get('/blog/fmla-in-2026')
        ->assertSuccessful()
        ->assertSee('FMLA in 2026', escape: false)
        ->assertSee('The body of the article.', escape: false);
});

/** A draft and an article that never existed look the same to a reader. */
it('hides an article that is not published', function (string $state): void {
    Post::factory()->{$state}()->create(['slug' => 'secret']);

    $this->get('/blog/secret')->assertNotFound();
})->with(['draft' => 'uncategorised', 'scheduled' => 'scheduled']);

it('suggests more from the same category', function (): void {
    $category = PostCategory::factory()->create();

    Post::factory()->in($category)->published()->create(['slug' => 'first', 'title' => 'First piece']);
    Post::factory()->in($category)->published()->create(['title' => 'Second piece']);
    Post::factory()->published()->create(['title' => 'Unrelated piece']);

    $this->get('/blog/first')
        ->assertSee('Second piece', escape: false)
        ->assertDontSee('Unrelated piece', escape: false);
});

// Previewing --------------------------------------------------------------------------

it('shows a draft article through a signed link', function (): void {
    $post = Post::factory()->create(['slug' => 'draft-piece', 'body' => '<p>Not finished.</p>']);

    $this->get('/blog/draft-piece')->assertNotFound();

    $this->get($post->previewUrl())
        ->assertSuccessful()
        ->assertSee('Not finished.', escape: false)
        ->assertSee('has not been published', escape: false);
});

it('refuses a blog preview link that was not signed', function (): void {
    $post = Post::factory()->create();

    $this->get('/blog/preview/'.$post->getKey())->assertForbidden();
});

it('refuses a blog preview link that has run out', function (): void {
    $post = Post::factory()->create();
    $link = $post->previewUrl();

    $this->travel(Post::PREVIEW_LIFETIME_DAYS + 1)->days();

    $this->get($link)->assertForbidden();
});

/** The preview route sits before the article route, so it is not read as a slug. */
it('does not mistake the preview path for an article', function (): void {
    Post::factory()->published()->create(['slug' => 'preview']);

    $this->get('/blog/preview')->assertSuccessful();
});

// Seed data is a deliverable (§5.9) ---------------------------------------------------

it('seeds a blog with something in every state', function (): void {
    $this->seed(BlogSeeder::class);

    expect(PostCategory::query()->count())->toBe(3)
        ->and(Post::query()->count())->toBe(4)
        ->and(Post::query()->live()->count())->toBe(3)
        // One left unpublished, so drafts and previews are reachable in review.
        ->and(Post::query()->where('status', PublishStatus::Draft)->count())->toBe(1);

    $this->get('/blog')->assertSuccessful();
});

it('seeds the blog idempotently', function (): void {
    $this->seed(BlogSeeder::class);
    $this->seed(BlogSeeder::class);

    expect(Post::query()->count())->toBe(4)
        ->and(PostCategory::query()->count())->toBe(3);
});

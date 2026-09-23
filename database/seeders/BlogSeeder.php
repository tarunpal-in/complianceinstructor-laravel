<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A few articles across a few categories — §5.9.
 *
 * A blog screen with nothing in it cannot be reviewed: the index, the category
 * filter, the related list and the reading estimate are all invisible until
 * there is something to draw. Three categories and four articles is enough for
 * every one of those to appear.
 *
 * The copy is deliberately provisional and says so. §12 has content readiness
 * as the most common cause of slipped launches, and placeholder text that reads
 * like finished writing is how it ships by accident.
 */
class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()->whereHas('roles')->orderBy('id')->first();

        $categories = collect([
            ['Regulatory updates', 'What changed, and what it means in practice.'],
            ['HR compliance', 'Policy, documentation and the awkward cases.'],
            ['Payroll', 'Wage and hour, year-end, multi-state.'],
        ])->map(fn (array $row, int $index): PostCategory => PostCategory::firstOrCreate(
            ['slug' => Str::slug($row[0])],
            ['name' => $row[0], 'description' => $row[1], 'sort_order' => $index * 10, 'is_active' => true],
        ));

        $articles = [
            ['FMLA in 2026: the three changes worth reading twice', 0, PublishStatus::Published, 3],
            ['Wage and hour audits: what to have ready before the letter', 2, PublishStatus::Published, 10],
            ['Documenting a reasonable accommodation, properly', 1, PublishStatus::Published, 24],
            // One unpublished, so the drafts tab and the preview link are both
            // reachable in review.
            ['Year-end payroll close: a working checklist', 2, PublishStatus::Draft, null],
        ];

        foreach ($articles as [$title, $categoryIndex, $status, $daysAgo]) {
            $post = Post::firstOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'title' => $title,
                    'excerpt' => 'Placeholder summary — to be replaced with the client\'s own copy before launch.',
                    'body' => '<p><strong>Placeholder article.</strong> This is seeded copy so the blog can be reviewed with something in it. It is not publishable writing.</p>'
                        .'<h2>What changed</h2><p>'.fake()->paragraph(6).'</p>'
                        .'<h3>What to do about it</h3><p>'.fake()->paragraph(8).'</p>'
                        .'<p>'.fake()->paragraph(7).'</p>',
                    'post_category_id' => $categories[$categoryIndex]->getKey(),
                    'user_id' => $author?->getKey(),
                    'status' => $status,
                    'published_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
                ],
            );

            // The same tag vocabulary the media library uses, namespaced.
            $post->tags()->syncWithoutDetaching(
                collect(['compliance', 'placeholder'])
                    ->map(fn (string $name): int => Tag::findOrCreateNamed($name, Tag::TYPE_POST)->getKey())
                    ->all(),
            );
        }

        $this->seedComments($author);
    }

    /**
     * One thread, in every state the moderation screen has a tab for.
     *
     * The queue is the screen that has to be reviewable, and a queue with
     * nothing in it shows neither the badge, the tabs, nor what a moderator's
     * day actually looks like. Put on the oldest article so it does not sit at
     * the top of the blog index pretending to be a busy site.
     */
    private function seedComments(?User $author): void
    {
        $post = Post::query()->live()->oldest('published_at')->first();

        if ($post === null || $post->comments()->exists()) {
            return;
        }

        $published = Comment::factory()->on($post)->approved()->create([
            'author_name' => 'Dana Whitfield',
            'author_email' => 'dana.whitfield@example.com',
            'body' => 'Placeholder comment. Useful for reviewing the thread — not real feedback from a real reader.',
            'created_at' => now()->subDays(4),
        ]);

        if ($author !== null) {
            Comment::factory()->replyTo($published)->by($author)->approved($author)->create([
                'body' => 'Placeholder reply, posted from the admin Comments screen.',
                'created_at' => now()->subDays(3),
            ]);
        }

        Comment::factory()->on($post)->create([
            'author_name' => 'Marcus Reed',
            'author_email' => 'marcus.reed@example.com',
            'body' => 'Placeholder comment awaiting review, so the queue has something in it.',
            'created_at' => now()->subHours(6),
        ]);

        Comment::factory()->on($post)->spam('Contains 4 links.')->create([
            'author_name' => 'cheap-seo-services',
            'author_email' => 'noreply@example.net',
            'body' => 'Placeholder spam, so the Spam tab and the filter\'s reasoning can both be seen.',
            'created_at' => now()->subHours(2),
        ]);
    }
}

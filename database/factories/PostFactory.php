<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        $title = Str::title(fake()->unique()->sentence(5));

        return [
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title),
            'excerpt' => fake()->sentence(18),
            'body' => '<p>'.implode('</p><p>', fake()->paragraphs(4)).'</p>',
            'post_category_id' => PostCategory::factory(),
            'user_id' => User::factory(),
            'status' => PublishStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /** Published, but held back until its date. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Published,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function uncategorised(): static
    {
        return $this->state(fn (): array => ['post_category_id' => null]);
    }

    public function in(PostCategory $category): static
    {
        return $this->state(fn (): array => ['post_category_id' => $category->getKey()]);
    }

    public function by(User $author): static
    {
        return $this->state(fn (): array => ['user_id' => $author->getKey()]);
    }

    /** Words enough to make the reading estimate predictable in a test. */
    public function ofLength(int $words): static
    {
        return $this->state(fn (): array => [
            'body' => '<p>'.implode(' ', array_fill(0, $words, 'word')).'</p>',
        ]);
    }
}

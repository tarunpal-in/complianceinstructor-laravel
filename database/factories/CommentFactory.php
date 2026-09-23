<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'post_id' => Post::factory()->published(),
            'parent_id' => null,
            'user_id' => null,
            'author_name' => $name,
            // Unique by default: history is decided by email, so two seeded
            // commenters sharing one would silently auto-approve each other.
            'author_email' => Str::lower(fake()->unique()->safeEmail()),
            'body' => fake()->paragraph(),
            'status' => CommentStatus::Pending,
            'spam_reason' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'moderated_at' => null,
            'moderated_by' => null,
        ];
    }

    public function approved(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => CommentStatus::Approved,
            'moderated_at' => now()->subHour(),
            'moderated_by' => $moderator?->getKey(),
        ]);
    }

    public function spam(string $reason = 'Contains 4 links.'): static
    {
        return $this->state(fn (): array => [
            'status' => CommentStatus::Spam,
            'spam_reason' => $reason,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => CommentStatus::Rejected,
            'moderated_at' => now()->subHour(),
        ]);
    }

    public function on(Post $post): static
    {
        return $this->state(fn (): array => ['post_id' => $post->getKey()]);
    }

    /** A reply hangs off its parent and inherits that parent's article. */
    public function replyTo(Comment $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->getKey(),
            'post_id' => $parent->post_id,
        ]);
    }

    /** Left by somebody with an account, rather than a guest. */
    public function by(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'author_name' => $user->name,
            'author_email' => Str::lower($user->email),
        ]);
    }

    public function from(string $email): static
    {
        return $this->state(fn (): array => ['author_email' => Str::lower($email)]);
    }
}

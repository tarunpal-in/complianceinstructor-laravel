<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'status' => PublishStatus::Draft,
            'published_at' => null,
            'layout' => 'standard',
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

    /**
     * With one block on it, so it is publishable.
     *
     * @param  array<string, mixed>  $data
     */
    public function withContent(string $type = 'rich_text', array $data = ['body' => '<p>Some copy.</p>']): static
    {
        return $this->afterCreating(function (Page $page) use ($type, $data): void {
            $page->sections()->create([
                'type' => $type,
                'data' => $data,
                'sort_order' => 0,
                'is_visible' => true,
            ]);

            $page->load('sections');
        });
    }
}

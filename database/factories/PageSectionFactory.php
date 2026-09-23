<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageSection>
 */
class PageSectionFactory extends Factory
{
    protected $model = PageSection::class;

    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'type' => 'rich_text',
            'data' => ['heading' => fake()->sentence(3), 'body' => '<p>'.fake()->paragraph().'</p>'],
            'sort_order' => 0,
            'is_visible' => true,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function type(string $type, array $data = []): static
    {
        return $this->state(fn (): array => ['type' => $type, 'data' => $data]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_visible' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(fn (): array => ['sort_order' => $position]);
    }
}

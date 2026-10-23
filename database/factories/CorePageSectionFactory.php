<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CorePage;
use App\Models\CorePageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CorePageSection> */
class CorePageSectionFactory extends Factory
{
    protected $model = CorePageSection::class;

    public function definition(): array
    {
        return [
            'core_page_id' => CorePage::factory(),
            'heading' => fake()->words(3, true),
            'body' => fake()->paragraphs(2, true),
            'sort_order' => 0,
            'is_visible' => true,
        ];
    }

    /** A body-only block, with no heading. */
    public function headingless(): static
    {
        return $this->state(fn (): array => ['heading' => null]);
    }
}

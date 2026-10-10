<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeoMeta>
 */
class SeoMetaFactory extends Factory
{
    protected $model = SeoMeta::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Attaches to a Page by default; a test or seeder overrides the
            // morph with ->for($model, 'seoable') for another content type.
            'seoable_type' => (new Page)->getMorphClass(),
            'seoable_id' => Page::factory(),
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->sentence(16),
            'keywords' => $this->faker->words(2, true),
            'robots' => 'index, follow',
        ];
    }

    public function noindex(): static
    {
        return $this->state(fn (): array => ['robots' => 'noindex, follow']);
    }
}

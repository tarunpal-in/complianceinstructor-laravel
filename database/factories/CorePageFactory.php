<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CorePageKey;
use App\Models\CorePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CorePage> */
class CorePageFactory extends Factory
{
    protected $model = CorePage::class;

    public function definition(): array
    {
        return [
            // Unique across the four fixed keys; use forKey() to pin one.
            'key' => fake()->unique()->randomElement(CorePageKey::values()),
            'hero_eyebrow' => mb_strtoupper(fake()->words(2, true)),
            'hero_title' => fake()->sentence(4),
            'hero_subtitle' => fake()->sentence(16),
            'hero_media_id' => null,
            'hero_overlay' => true,
            'cta_label' => null,
            'cta_url' => null,
        ];
    }

    public function forKey(CorePageKey $key): static
    {
        return $this->state(fn (): array => ['key' => $key->value]);
    }
}

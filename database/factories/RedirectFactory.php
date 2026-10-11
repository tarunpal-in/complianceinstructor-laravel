<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'from_path' => Redirect::normalise('/'.$this->faker->unique()->slug()),
            'to_path' => '/'.$this->faker->slug(),
            'status_code' => 301,
            'is_active' => true,
            'source' => Redirect::SOURCE_MANUAL,
        ];
    }

    public function temporary(): static
    {
        return $this->state(fn (): array => ['status_code' => 302]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function external(): static
    {
        return $this->state(fn (): array => ['to_path' => 'https://example.test/elsewhere']);
    }
}

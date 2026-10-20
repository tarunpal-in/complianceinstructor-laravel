<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Webinar;
use App\Models\WebinarHandout;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebinarHandout> */
class WebinarHandoutFactory extends Factory
{
    protected $model = WebinarHandout::class;

    public function definition(): array
    {
        return [
            'webinar_id' => Webinar::factory(),
            'label' => fake()->words(3, true),
            'disk_path' => 'webinar-handouts/'.fake()->uuid().'.pdf',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

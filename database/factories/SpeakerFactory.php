<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Speaker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Speaker> */
class SpeakerFactory extends Factory
{
    protected $model = Speaker::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'title' => fake()->randomElement([
                'HR Compliance Specialist', 'Payroll Operations Lead',
                'EHS Director', 'Employment Counsel', 'Executive Leadership Coach',
            ]),
            'bio' => fake()->paragraphs(2, true),
            'linkedin_url' => 'https://www.linkedin.com/in/'.Str::slug($name),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

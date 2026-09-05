<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Industry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Industry> */
class IndustryFactory extends Factory
{
    protected $model = Industry::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Human Resources', 'Payroll', 'Manufacturing',
            'Microsoft Office', 'Healthcare', 'Financial Services',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(12),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}

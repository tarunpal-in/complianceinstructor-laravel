<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Accreditation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Accreditation> */
class AccreditationFactory extends Factory
{
    protected $model = Accreditation::class;

    public function definition(): array
    {
        $code = fake()->unique()->randomElement(['HRCI', 'SHRM', 'CPE', 'APA']);

        return [
            'code' => $code,
            'name' => match ($code) {
                'HRCI' => 'HR Certification Institute',
                'SHRM' => 'Society for Human Resource Management',
                'CPE' => 'Continuing Professional Education',
                default => 'American Payroll Association',
            },
            'description' => fake()->sentence(10),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}

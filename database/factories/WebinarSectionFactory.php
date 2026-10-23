<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Webinar;
use App\Models\WebinarSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebinarSection> */
class WebinarSectionFactory extends Factory
{
    protected $model = WebinarSection::class;

    public function definition(): array
    {
        return [
            'webinar_id' => Webinar::factory(),
            'heading' => fake()->randomElement([
                'Webinar Overview', 'Session Highlights', 'Why You Should Attend', 'Who Should Attend',
            ]),
            'body' => fake()->paragraphs(2, true),
            'sort_order' => 0,
            'is_visible' => true,
        ];
    }

    /** A body-only section, as the migrated delivery notice is stored. */
    public function headingless(): static
    {
        return $this->state(fn (): array => ['heading' => null]);
    }
}

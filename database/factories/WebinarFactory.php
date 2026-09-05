<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Industry;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Webinar> */
class WebinarFactory extends Factory
{
    protected $model = Webinar::class;

    public function definition(): array
    {
        $title = fake()->randomElement([
            'FMLA Compliance: Avoiding Costly Mistakes',
            'Payroll Year-End Close: What Changed',
            'OSHA Recordkeeping for Manufacturing Teams',
            'Wage & Hour Audits: Preparing Before the Letter',
            'Multi-State Remote Work: Tax and Compliance',
            'I-9 and E-Verify: Getting It Right',
        ]).' '.fake()->numberBetween(2026, 2027);

        return [
            'type' => WebinarType::Live,
            'status' => WebinarStatus::Draft,
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'summary' => fake()->sentence(20),
            'description' => fake()->paragraphs(2, true),
            'overview' => fake()->paragraphs(3, true),
            'background' => fake()->paragraph(),
            'who_should_attend' => fake()->paragraphs(2, true),
            'why_attend' => fake()->paragraphs(2, true),
            'session_highlights' => fake()->paragraphs(2, true),
            'attendee_instructions' => null,
            'speaker_id' => Speaker::factory(),
            'industry_id' => Industry::factory(),
            'scheduled_at' => fake()->dateTimeBetween('+1 week', '+4 months'),
            'timezone' => 'America/Los_Angeles',
            'duration_minutes' => fake()->randomElement([60, 75, 90, 120]),
            'seats_total' => fake()->randomElement([null, 100, 250]),
        ];
    }

    /** A recorded webinar has no session, so no date. */
    public function recorded(): static
    {
        return $this->state(fn (): array => [
            'type' => WebinarType::Recorded,
            'scheduled_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => ['status' => WebinarStatus::Scheduled]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => WebinarStatus::Completed,
            'scheduled_at' => fake()->dateTimeBetween('-6 months', '-1 week'),
        ]);
    }
}

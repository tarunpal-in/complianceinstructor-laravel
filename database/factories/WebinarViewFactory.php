<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebinarView>
 */
class WebinarViewFactory extends Factory
{
    protected $model = WebinarView::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'webinar_id' => Webinar::factory(),
            'furthest_seconds' => 0,
            'seconds_watched' => 0,
            'completed_at' => null,
            'last_watched_at' => now(),
        ];
    }

    public function reached(int $seconds): static
    {
        return $this->state(fn (): array => [
            'furthest_seconds' => $seconds,
            'seconds_watched' => $seconds,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()]);
    }
}

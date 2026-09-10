<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Format;
use App\Models\Coupon;
use App\Models\Industry;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(8)),
            'description' => fake()->sentence(4),
            'percent_off' => 10,
            'amount_off_cents' => null,
            'currency' => 'USD',
            'max_redemptions' => null,
            'max_per_user' => null,
            'minimum_spend_cents' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (): array => [
            'percent_off' => $percent,
            'amount_off_cents' => null,
        ]);
    }

    public function fixed(int $cents): static
    {
        return $this->state(fn (): array => [
            'percent_off' => null,
            'amount_off_cents' => $cents,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function notYetStarted(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->addWeek()]);
    }

    public function cappedAt(int $total, ?int $perUser = null): static
    {
        return $this->state(fn (): array => [
            'max_redemptions' => $total,
            'max_per_user' => $perUser,
        ]);
    }

    public function minimumSpend(int $cents): static
    {
        return $this->state(fn (): array => ['minimum_spend_cents' => $cents]);
    }

    public function forWebinar(Webinar $webinar): static
    {
        return $this->state(fn (): array => [
            'scopeable_type' => Webinar::class,
            'scopeable_id' => $webinar->getKey(),
        ]);
    }

    public function forIndustry(Industry $industry): static
    {
        return $this->state(fn (): array => [
            'scopeable_type' => Industry::class,
            'scopeable_id' => $industry->getKey(),
        ]);
    }

    public function forFormat(Format $format): static
    {
        return $this->state(fn (): array => ['scope_format' => $format]);
    }
}

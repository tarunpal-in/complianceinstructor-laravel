<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AllowanceWindow;
use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MembershipPlan>
 */
class MembershipPlanFactory extends Factory
{
    protected $model = MembershipPlan::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Membership';

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(12),
            'price_cents' => fake()->numberBetween(9900, 99900),
            'currency' => 'USD',
            'billing_period' => BillingPeriod::Year,
            'duration_months' => null,
            'stripe_price_id' => 'price_'.Str::random(20),
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (): array => [
            'billing_period' => BillingPeriod::Month,
            'duration_months' => null,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn (): array => [
            'billing_period' => BillingPeriod::Quarter,
            'duration_months' => null,
        ]);
    }

    /** A one-off purchase of a fixed span of access — no Stripe price needed. */
    public function oneTime(int $months = 12): static
    {
        return $this->state(fn (): array => [
            'billing_period' => BillingPeriod::OneTime,
            'duration_months' => $months,
            'stripe_price_id' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    /**
     * Give the plan a set of allowances: `['live' => 4, 'transcript' => null]`,
     * where null is unlimited.
     *
     * @param  array<string, int|null>  $allowances
     */
    public function granting(array $allowances, AllowanceWindow $window = AllowanceWindow::Term): static
    {
        return $this->afterCreating(function (MembershipPlan $plan) use ($allowances, $window): void {
            foreach ($allowances as $format => $quantity) {
                $plan->entitlements()->create([
                    'format' => Format::from($format),
                    'quantity' => $quantity,
                    'window' => $window,
                ]);
            }

            $plan->load('entitlements');
        });
    }
}

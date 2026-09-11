<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AllowanceWindow;
use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanEntitlement>
 */
class PlanEntitlementFactory extends Factory
{
    protected $model = PlanEntitlement::class;

    public function definition(): array
    {
        return [
            'membership_plan_id' => MembershipPlan::factory(),
            'format' => Format::Live,
            'quantity' => 4,
            'window' => AllowanceWindow::Term,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn (): array => ['quantity' => null]);
    }

    public function format(Format $format): static
    {
        return $this->state(fn (): array => ['format' => $format]);
    }

    public function perMonth(): static
    {
        return $this->state(fn (): array => ['window' => AllowanceWindow::Month]);
    }
}

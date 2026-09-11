<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AllowanceWindow;
use App\Enums\Format;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionEntitlement>
 */
class SubscriptionEntitlementFactory extends Factory
{
    protected $model = SubscriptionEntitlement::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
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
}

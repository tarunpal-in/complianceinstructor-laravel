<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Cashier ships no factory, and every membership test needs a subscriber.
 *
 * The Stripe-shaped columns are filled with plausible values rather than left
 * null because Cashier's own scopes read `stripe_status` — a subscription with
 * no status is not "inactive", it is invisible to `active()`, which is a
 * confusing way for a test to fail.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'membership_plan_id' => MembershipPlan::factory(),
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_'.Str::random(20),
            'quantity' => 1,
            'price_cents' => 19900,
            'trial_ends_at' => null,
            'ends_at' => null,
        ];
    }

    public function forPlan(MembershipPlan $plan): static
    {
        return $this->state(fn (): array => [
            'membership_plan_id' => $plan->getKey(),
            'price_cents' => $plan->price_cents,
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (): array => [
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDay(),
        ]);
    }

    /** Cancelled, but still inside the period they paid for. */
    public function onGracePeriod(): static
    {
        return $this->state(fn (): array => [
            'stripe_status' => 'active',
            'ends_at' => now()->addWeek(),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn (): array => ['stripe_status' => 'past_due']);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $placedAt = fake()->dateTimeBetween('-1 year', 'now');

        return [
            // Random rather than sequential: a factory must not depend on what
            // is already in the table, and the uniqueness that matters is
            // asserted by the column.
            'number' => 'CI-'.$placedAt->format('Y').'-'.fake()->unique()->numberBetween(1000, 9999),
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 0,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'currency' => 'USD',
            'billing_snapshot' => [
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'company' => fake()->company(),
                'country' => 'US',
            ],
            'placed_at' => $placedAt,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Paid]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Pending]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Failed]);
    }

    public function refunded(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Refunded]);
    }

    /** A checkout that was never signed in — §7 P3 has guest checkout. */
    public function guest(): static
    {
        return $this->state(fn (): array => ['user_id' => null]);
    }

    /** Totals that agree with the lines, for tests that assert arithmetic. */
    public function totalling(int $subtotalCents, int $discountCents = 0, int $taxCents = 0): static
    {
        return $this->state(fn (): array => [
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $discountCents,
            'tax_cents' => $taxCents,
            'total_cents' => $subtotalCents - $discountCents + $taxCents,
        ]);
    }
}

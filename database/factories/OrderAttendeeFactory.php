<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderAttendee;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAttendee>
 */
class OrderAttendeeFactory extends Factory
{
    protected $model = OrderAttendee::class;

    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'user_id' => null,
        ];
    }
}

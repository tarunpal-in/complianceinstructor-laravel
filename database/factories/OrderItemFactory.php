<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Format;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unit = fake()->randomElement([17900, 19900, 27900]);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'description' => 'Live Webinar',
            'sku' => Str::upper(Str::random(10)),
            'quantity' => $quantity,
            'unit_price_cents' => $unit,
            // Always the product, never the unit price (§14 #7).
            'line_total_cents' => $unit * $quantity,
            'seats_per_unit' => 1,
        ];
    }

    /** @param  array<int, Format>  $formats */
    public function granting(array $formats): static
    {
        return $this->afterCreating(function (OrderItem $item) use ($formats): void {
            foreach ($formats as $format) {
                $item->formats()->create(['format' => $format]);
            }

            $item->update([
                'description' => implode(' + ', array_map(
                    static fn (Format $format): string => $format->label(),
                    $formats,
                )),
            ]);
        });
    }
}

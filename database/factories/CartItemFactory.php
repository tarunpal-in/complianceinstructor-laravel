<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CartItem;
use App\Models\Offering;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CartItem> */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        return [
            'cart_id' => \App\Models\Cart::factory(),
            'offering_id' => Offering::factory(),
            'quantity' => 1,
            'unit_price_cents' => 19900,
        ];
    }
}

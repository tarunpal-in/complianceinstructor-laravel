<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Cart> */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'session_token' => Str::random(40),
            'currency' => 'USD',
            'expires_at' => null,
        ];
    }

    /** A cart belonging to a signed-in user rather than a guest session. */
    public function forUser(\App\Models\User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id, 'session_token' => null]);
    }
}

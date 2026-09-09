<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'gateway' => PaymentGateway::Stripe,
            'gateway_payment_id' => 'pi_'.Str::lower(Str::random(24)),
            'status' => PaymentStatus::Succeeded,
            'amount_cents' => 19900,
            'currency' => 'USD',
            'processed_at' => now(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
            'amount_cents' => $order->total_cents,
        ]);
    }

    public function paypal(): static
    {
        return $this->state(fn (): array => [
            'gateway' => PaymentGateway::PayPal,
            'gateway_payment_id' => Str::upper(Str::random(17)),
        ]);
    }

    /** Invoiced B2B — no gateway id, so the UNIQUE stays satisfied by NULL. */
    public function manual(): static
    {
        return $this->state(fn (): array => [
            'gateway' => PaymentGateway::Manual,
            'gateway_payment_id' => null,
            'reference' => 'BACS '.fake()->numerify('########'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'processed_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Pending,
            'processed_at' => null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'gateway_refund_id' => 're_'.Str::lower(Str::random(24)),
            'status' => RefundStatus::Succeeded,
            'amount_cents' => 19900,
            'reason' => 'Customer requested within the 7-day window',
            'processed_at' => now(),
        ];
    }

    public function partialOf(Payment $payment, int $cents): static
    {
        return $this->state(fn (): array => [
            'payment_id' => $payment->id,
            'amount_cents' => $cents,
        ]);
    }
}

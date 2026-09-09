<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\PaymentGatewayDriver;
use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Payments\RefundResult;

/**
 * A gateway that does what the test tells it to.
 *
 * The Stripe and PayPal drivers cannot be exercised without sandbox credentials
 * and a network, so what is tested here is everything around them: the ceiling,
 * the reservation, the idempotency key, and what the application does with a
 * decline. Swapping this in through the manager is the reason the contract
 * exists.
 */
class FakeGatewayDriver implements PaymentGatewayDriver
{
    /** @var array<int, array{amount: int, reason: string, key: string}> */
    public array $calls = [];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly bool $configured = true,
        private readonly ?string $declineWith = null,
        private readonly ?string $reference = 're_fake123',
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function refund(
        Payment $payment,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): RefundResult {
        $this->calls[] = ['amount' => $amountCents, 'reason' => $reason, 'key' => $idempotencyKey];

        if ($this->declineWith !== null) {
            return RefundResult::failed($this->declineWith);
        }

        // A distinct reference per call, because a real gateway issues one and
        // `refunds.gateway_refund_id` is UNIQUE — a fake handing back the same
        // id twice fails on the constraint rather than on the behaviour under
        // test.
        $suffix = count($this->calls) > 1 ? '-'.count($this->calls) : '';

        return RefundResult::succeeded(
            $this->reference === null ? null : $this->reference.$suffix,
        );
    }
}

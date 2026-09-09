<?php

declare(strict_types=1);

namespace App\Payments;

use App\Contracts\PaymentGatewayDriver;
use App\Enums\PaymentGateway;
use InvalidArgumentException;

/**
 * Resolves the driver for a gateway.
 *
 * Drivers are registered rather than newed up at the call site, so tests can
 * swap in a fake and no part of the application has to know which class handles
 * which gateway.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGatewayDriver> */
    private array $drivers = [];

    public function register(PaymentGatewayDriver $driver): void
    {
        $this->drivers[$driver->gateway()->value] = $driver;
    }

    public function driver(PaymentGateway $gateway): PaymentGatewayDriver
    {
        return $this->drivers[$gateway->value]
            ?? throw new InvalidArgumentException("No driver is registered for {$gateway->value}.");
    }

    public function has(PaymentGateway $gateway): bool
    {
        return isset($this->drivers[$gateway->value]);
    }
}

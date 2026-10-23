<?php

declare(strict_types=1);

namespace App\Import\Maps;

use App\Enums\OrderStatus;
use RuntimeException;

/**
 * WooCommerce order status → the new `OrderStatus`.
 *
 * In the live store the status is the authority; in the new model status is
 * derived from payments (§9), so this is only the *imported starting point* —
 * the order's payment rows are what later keep it honest. `isSettled()` on the
 * result is what Phase 4 reads to decide whether to grant entitlements.
 */
final class LegacyOrderStatus
{
    /**
     * @var array<string, OrderStatus>
     */
    private const MAP = [
        'completed' => OrderStatus::Paid,
        // Payment taken, fulfilment outstanding — money is in, so Paid.
        'processing' => OrderStatus::Paid,
        // Awaiting an offline payment (bank transfer, cheque): nothing taken yet.
        'on-hold' => OrderStatus::Pending,
        'pending' => OrderStatus::Pending,
        'cancelled' => OrderStatus::Cancelled,
        'failed' => OrderStatus::Failed,
        'refunded' => OrderStatus::Refunded,
        // A checkout that was still a draft when exported.
        'checkout-draft' => OrderStatus::Pending,
    ];

    public static function toEnum(string $wcStatus): OrderStatus
    {
        $key = str_starts_with($wcStatus, 'wc-') ? substr($wcStatus, 3) : $wcStatus;
        $key = strtolower(trim($key));

        if (! isset(self::MAP[$key])) {
            throw new RuntimeException("Unmapped WooCommerce order status '{$wcStatus}'. Add it to ".self::class.' before importing.');
        }

        return self::MAP[$key];
    }

    public static function knows(string $wcStatus): bool
    {
        $key = str_starts_with($wcStatus, 'wc-') ? substr($wcStatus, 3) : $wcStatus;

        return isset(self::MAP[strtolower(trim($key))]);
    }
}

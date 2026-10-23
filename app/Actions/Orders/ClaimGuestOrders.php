<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Entitlements\GrantEntitlement;
use App\Actions\Entitlements\RevokeEntitlement;
use App\Enums\EntitlementSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Links a verified account to the guest orders placed under its email, and
 * grants the access they bought — CLAUDE.md §5.2.
 *
 * Roughly 2,352 of the migrated orders were guest checkouts with no account, so
 * their buyers hold no entitlement yet. When such a person registers and
 * verifies that email (or an imported customer whose guest orders were never
 * linked logs in), this finds the orders by their frozen billing email, points
 * them at the account, claims the matching attendee seats, and grants the buyer
 * one entitlement per webinar × format through the same GrantEntitlement every
 * live purchase uses — so access appears on their dashboard with no support
 * ticket.
 *
 * Only ever runs for an address the account has proven it owns (email verified
 * or a verified login), so it cannot be used to claim someone else's purchases.
 * Idempotent: an order already linked is out of scope, and each grant keys on
 * the unique tuple.
 */
class ClaimGuestOrders
{
    public function __construct(
        private readonly GrantEntitlement $grant,
        private readonly RevokeEntitlement $revoke,
    ) {}

    /** @return int how many orders were claimed */
    public function __invoke(User $user): int
    {
        $email = strtolower(trim($user->email));

        if ($email === '') {
            return 0;
        }

        $orders = Order::query()
            ->whereNull('user_id')
            ->where('billing_snapshot->email', $email)
            ->with(['items.formats', 'items.webinar', 'items.attendees'])
            ->get();

        $claimed = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $user, $email): void {
                $order->forceFill(['user_id' => $user->getKey()])->save();

                foreach ($order->items as $item) {
                    foreach ($item->attendees as $attendee) {
                        if ($attendee->user_id === null && strtolower((string) $attendee->email) === $email) {
                            $attendee->forceFill(['user_id' => $user->getKey()])->save();
                        }
                    }
                }

                // Only a settled order granted anything; a fully refunded one is
                // granted then revoked, so the ledger reads the same as it would
                // for a live purchase.
                if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true)) {
                    return;
                }

                $isRefunded = $order->status === OrderStatus::Refunded;

                foreach ($order->items as $item) {
                    $webinar = $item->webinar;

                    if ($webinar === null) {
                        continue;
                    }

                    foreach ($item->formatEnums() as $format) {
                        $entitlement = ($this->grant)(
                            $user,
                            $webinar,
                            $format,
                            EntitlementSource::Order,
                            $order->getKey(),
                            null,
                            'Claimed historical purchase',
                        );

                        if ($isRefunded && ! $entitlement->isRevoked()) {
                            ($this->revoke)($entitlement, 'Order refunded', null);
                        }
                    }
                }
            });

            $claimed++;
        }

        return $claimed;
    }
}

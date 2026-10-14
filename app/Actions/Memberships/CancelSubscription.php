<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\Subscription;
use RuntimeException;

/**
 * Cancels a membership at the end of the paid period — CLAUDE.md §6 / P4.
 *
 * Cancel, not cancel-now: the member keeps access until the term they already
 * paid for runs out (Cashier's grace period), and `Subscription::state()` reports
 * `Ending` meanwhile. Access stops on its own when `accessEndsAt()` passes — no
 * entitlement is revoked here, because the ones they spent were theirs to keep
 * for the window they spent them in.
 */
class CancelSubscription
{
    public function __invoke(Subscription $subscription): Subscription
    {
        if ($subscription->canceled()) {
            throw new RuntimeException('This membership is already set to end.');
        }

        $subscription->cancel();

        return $subscription->refresh();
    }
}

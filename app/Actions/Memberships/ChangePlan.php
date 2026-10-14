<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\MembershipPlan;
use App\Models\Subscription;
use RuntimeException;

/**
 * Moves a member to a different plan — an upgrade or a downgrade (CLAUDE.md §6 /
 * §8, "upgrade, downgrade" through Cashier) / P4.
 *
 * Cashier's swap prorates the difference at Stripe. Then the allowance snapshot
 * is rewritten to the new plan (SnapshotPlanEntitlements replaces the rows
 * wholesale, so an allowance the new plan does not grant cannot linger — §5.2),
 * and the price is moved to the new plan's: a plan change is exactly the case
 * where what the member pays *should* follow the plan, unlike a re-snapshot for
 * an admin correction, which deliberately preserves it.
 *
 * The term is not touched: swapping does not restart the clock on a fixed term,
 * and a recurring plan has no term to move.
 */
class ChangePlan
{
    public function __construct(private readonly SnapshotPlanEntitlements $snapshot) {}

    public function __invoke(Subscription $subscription, MembershipPlan $newPlan): Subscription
    {
        if (blank($newPlan->stripe_price_id) || ! $newPlan->billing_period->isRecurring()) {
            throw new RuntimeException('That plan cannot be subscribed to.');
        }

        if ($subscription->stripe_price === $newPlan->stripe_price_id) {
            throw new RuntimeException('You are already on that plan.');
        }

        $subscription->swap((string) $newPlan->stripe_price_id);

        ($this->snapshot)($subscription, $newPlan);

        // The snapshot keeps the old price on purpose; a plan change is the one
        // case that must move it, so this is the correction, after the rows.
        $subscription->forceFill(['price_cents' => $newPlan->price_cents])->save();

        return $subscription->refresh();
    }
}

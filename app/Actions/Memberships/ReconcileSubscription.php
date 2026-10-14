<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\MembershipPlan;
use App\Models\Subscription;

/**
 * Brings a subscription's allowance snapshot back into step with the plan Stripe
 * actually bills — CLAUDE.md §8 / P4.
 *
 * Our ChangePlan already re-snapshots when a member swaps in the app. This is
 * the safety net for the plan changing anywhere else — an admin editing the
 * subscription in the Stripe dashboard, or a `customer.subscription.updated`
 * webhook that Cashier applied to `stripe_price` while our `membership_plan_id`
 * and allowance rows stayed behind. It runs from the webhook (at once) and from
 * the daily `memberships:reconcile` sweep (in case a webhook was missed), so a
 * drift is corrected within one job cycle at worst.
 *
 * Access itself is not reconciled here: a redeemed entitlement expires with the
 * term it was spent in, and `Subscription::state()` stops granting the moment
 * the grace period passes — both at query time, so neither needs a job.
 */
class ReconcileSubscription
{
    public function __construct(private readonly SnapshotPlanEntitlements $snapshot) {}

    /** @return bool whether anything was changed */
    public function __invoke(Subscription $subscription): bool
    {
        if (blank($subscription->stripe_price)) {
            return false;
        }

        $billedPlan = MembershipPlan::query()
            ->where('stripe_price_id', $subscription->stripe_price)
            ->first();

        // A price we do not recognise is not ours to reconcile — leaving it be is
        // safer than snapshotting a plan that was guessed at.
        if ($billedPlan === null) {
            return false;
        }

        if ((int) $subscription->membership_plan_id === $billedPlan->getKey()) {
            return false;
        }

        // The snapshot keeps the member's old price on purpose; a plan change is
        // the one case that must move it, so this corrects it after the rows.
        ($this->snapshot)($subscription, $billedPlan);
        $subscription->forceFill(['price_cents' => $billedPlan->price_cents])->save();

        return true;
    }
}

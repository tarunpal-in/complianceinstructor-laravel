<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\MembershipPlan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies a plan's allowances onto a subscription, at the moment of signup.
 *
 * Agreed with Tarun on 2026-09-11: an active subscriber keeps the terms they
 * signed up under. This action is where that becomes true — after it runs, the
 * plan can be edited freely and this subscriber is unaffected, because nothing
 * downstream ever reads `plan_entitlements` again for them (§5.2, P4).
 *
 * The same reasoning as `order_items` freezing a price: a promise that changes
 * after it was made is not a promise. §4 rule 2 — this is an Action because it
 * is the step that makes a subscription mean something, and it will be called
 * from the subscribe flow, from a Stripe webhook, and from the admin.
 */
class SnapshotPlanEntitlements
{
    /**
     * @return Collection<int, SubscriptionEntitlement>
     */
    public function __invoke(Subscription $subscription, ?MembershipPlan $plan = null): Collection
    {
        if ($plan === null) {
            // loadMissing rather than a bare read: this runs from a webhook too,
            // where the subscription was retrieved from the database and
            // Model::shouldBeStrict() turns a lazy load into an exception.
            $subscription->loadMissing('plan');

            $plan = $subscription->plan;
        }

        if ($plan === null) {
            return new Collection;
        }

        $plan->loadMissing('entitlements');

        return DB::transaction(function () use ($subscription, $plan): Collection {
            /*
             * Replaced wholesale rather than merged. A re-snapshot happens on a
             * plan change — an upgrade, or an admin correcting what someone was
             * sold — and merging would leave behind an allowance for a format
             * the new plan does not grant, which reads as access the customer
             * never bought.
             */
            SubscriptionEntitlement::query()
                ->where('subscription_id', $subscription->getKey())
                ->delete();

            $rows = [];

            foreach ($plan->entitlements as $entitlement) {
                /** @var PlanEntitlement $entitlement */
                $rows[] = SubscriptionEntitlement::create([
                    'subscription_id' => $subscription->getKey(),
                    'format' => $entitlement->format,
                    'quantity' => $entitlement->quantity,
                    'window' => $entitlement->window,
                ]);
            }

            // The price is part of the same promise: a repriced plan must not
            // rewrite what an existing member is told they pay.
            $subscription->forceFill([
                'membership_plan_id' => $plan->getKey(),
                'price_cents' => $subscription->price_cents ?? $plan->price_cents,
            ])->save();

            $snapshot = new Collection($rows);

            $subscription->setRelation('entitlements', $snapshot);

            return $snapshot;
        });
    }
}

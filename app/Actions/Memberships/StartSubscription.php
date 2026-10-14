<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use RuntimeException;

/**
 * Starts a membership — CLAUDE.md §4 rule 2 / §8 (Cashier owns subscription
 * billing) / P4.
 *
 * Cashier creates the Stripe subscription from a payment method the browser has
 * already collected and confirmed (SetupIntent + Elements, so 3DS happened at
 * the card step), then SnapshotPlanEntitlements freezes the plan's allowances,
 * price and term onto the row (§5.2) — the one place a subscription becomes
 * something a member can spend.
 *
 * The guard is the testable half and runs before any Stripe call: one active
 * membership at a time. A second `default` subscription would bill twice and
 * leave two allowance snapshots competing over the same ledger.
 */
class StartSubscription
{
    public function __construct(private readonly SnapshotPlanEntitlements $snapshot) {}

    public function __invoke(User $user, MembershipPlan $plan, string $paymentMethodId): Subscription
    {
        if (blank($plan->stripe_price_id)) {
            throw new RuntimeException('This plan cannot be subscribed to yet.');
        }

        if ($user->subscribed('default')) {
            throw new RuntimeException('You already have an active membership. Manage it from your account.');
        }

        // Cashier's builder is typed to return its own Subscription in the stubs,
        // though useSubscriptionModel() makes it ours at runtime. Re-read by key
        // so the type is honest rather than cast, and the snapshot runs on the
        // model that carries the plan link and the allowance rows.
        $created = $user->newSubscription('default', (string) $plan->stripe_price_id)
            ->create($paymentMethodId);

        $subscription = Subscription::query()->findOrFail($created->getKey());

        ($this->snapshot)($subscription, $plan);

        return $subscription;
    }
}

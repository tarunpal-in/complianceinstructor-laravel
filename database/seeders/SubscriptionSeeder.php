<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * "A few mid-term memberships" — CLAUDE.md §5.9, which makes seed data a
 * deliverable.
 *
 * One of each state the screen has to render, because a subscriber list that
 * only ever shows healthy rows looks finished until the first failed payment
 * and the first cancellation arrive together in production.
 *
 * Every one gets a snapshot through the real action rather than by writing
 * `subscription_entitlements` directly, so the seeded data is shaped the way a
 * real signup shapes it — including the member deliberately left on old terms,
 * who exists so "Terms differ" is reachable in review.
 */
class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $plans = MembershipPlan::query()->with('entitlements')->orderBy('sort_order')->get();

        if ($plans->isEmpty()) {
            $this->command->warn('No membership plans to subscribe to — skipping subscriptions.');

            return;
        }

        $snapshot = app(SnapshotPlanEntitlements::class);

        $members = [
            ['Priya Raman', 'priya.raman@example.com'],
            ['Daniel Okonkwo', 'daniel.okonkwo@example.com'],
            ['Hannah Feldman', 'hannah.feldman@example.com'],
            ['Luis Moreno', 'luis.moreno@example.com'],
            ['Grace Adeyemi', 'grace.adeyemi@example.com'],
        ];

        // Paid up and renewing — the ordinary case, and most of the list.
        $this->subscribe($snapshot, $members[0], $plans->first(), fn (): array => [
            'created_at' => now()->subMonths(2),
        ]);

        $this->subscribe($snapshot, $members[1], $plans->get(1) ?? $plans->first(), fn (): array => [
            'created_at' => now()->subMonths(5),
        ]);

        // Cancelled, still inside the period they paid for. The state the
        // status column exists to make visible.
        $this->subscribe($snapshot, $members[2], $plans->get(1) ?? $plans->first(), fn (): array => [
            'created_at' => now()->subMonths(4),
            'ends_at' => now()->addWeeks(3),
        ]);

        // A card that stopped working. Someone has to chase this.
        $this->subscribe($snapshot, $members[3], $plans->get(2) ?? $plans->first(), fn (): array => [
            'stripe_status' => 'past_due',
            'created_at' => now()->subMonths(7),
        ]);

        // Over. Kept because the record of what they bought outlives them
        // being a member.
        $this->subscribe($snapshot, $members[4], $plans->last(), fn (): array => [
            'created_at' => now()->subYear(),
            'ends_at' => now()->subMonth(),
        ]);

        $this->leaveOneOnOldTerms();
    }

    /**
     * @param  array{0: string, 1: string}  $member
     * @param  callable(): array<string, mixed>  $attributes
     */
    private function subscribe(
        SnapshotPlanEntitlements $snapshot,
        array $member,
        MembershipPlan $plan,
        callable $attributes,
    ): void {
        $user = User::firstOrCreate(
            ['email' => $member[1]],
            ['name' => $member[0], 'password' => 'password'],
        );

        $user->forceFill(['email_verified_at' => now()])->save();

        // Keyed on the member and plan so re-running the seeder does not give
        // the same person a second copy of the same membership.
        $subscription = Subscription::firstOrCreate(
            ['user_id' => $user->getKey(), 'membership_plan_id' => $plan->getKey()],
            [
                'type' => 'default',
                'stripe_id' => 'sub_seed_'.$user->getKey().'_'.$plan->getKey(),
                'stripe_status' => 'active',
                'stripe_price' => $plan->stripe_price_id ?? 'price_seed_'.$plan->slug,
                'quantity' => 1,
                'price_cents' => $plan->price_cents,
                ...$attributes(),
            ],
        );

        if ($subscription->entitlements()->doesntExist()) {
            $snapshot($subscription, $plan);
        }
    }

    /**
     * Make one member's terms differ from their plan.
     *
     * The snapshot is the whole point of §5.5 and it is invisible while every
     * subscriber matches their plan exactly. This member reads as somebody who
     * joined back when the plan granted less — the "Terms differ" badge, the
     * filter and "Re-apply plan terms" all become reachable in client review
     * because of this one row.
     *
     * The *subscription* is altered and never the plan. Editing the plan would
     * change the pricing page too, and the seeded catalogue has to keep
     * matching what the client signed off.
     */
    private function leaveOneOnOldTerms(): void
    {
        $subscription = Subscription::query()
            ->with('entitlements')
            ->whereNotNull('membership_plan_id')
            ->whereNull('ends_at')
            ->oldest('id')
            ->first();

        $entitlement = $subscription?->entitlements
            ->first(fn (SubscriptionEntitlement $row): bool => $row->quantity !== null && $row->quantity > 1);

        $entitlement?->update(['quantity' => (int) $entitlement->quantity - 1]);
    }
}

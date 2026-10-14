<?php

declare(strict_types=1);

use App\Actions\Memberships\ReconcileSubscription;
use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use Laravel\Cashier\Events\WebhookReceived;

/*
 * Keeping membership snapshots in step with Stripe — §8 / P4 slice 4. Access and
 * expiry are query-time, so what a job (or a webhook) must fix is a plan that
 * moved out from under the allowance snapshot.
 */

/**
 * A subscription stored against plan A but billed by Stripe for plan B — the
 * drift a webhook or the sweep has to reconcile.
 *
 * @return array{0: Subscription, 1: MembershipPlan, 2: MembershipPlan}
 */
function driftedSubscription(): array
{
    $planA = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create(['price_cents' => 19900]);
    $planB = MembershipPlan::factory()->monthly()->granting([Format::Recorded->value => 2])->create(['price_cents' => 29900]);

    $subscription = Subscription::factory()->forPlan($planA)->create(['stripe_price' => $planB->stripe_price_id]);
    app(SnapshotPlanEntitlements::class)($subscription, $planA);

    return [$subscription->refresh(), $planA, $planB];
}

it('re-snapshots a subscription onto the plan Stripe bills', function (): void {
    [$subscription, , $planB] = driftedSubscription();

    $changed = app(ReconcileSubscription::class)($subscription);

    $subscription->refresh()->loadMissing('entitlements');

    expect($changed)->toBeTrue()
        ->and((int) $subscription->membership_plan_id)->toBe($planB->getKey())
        ->and($subscription->price_cents)->toBe(29900)
        // Plan B grants recorded, not live — the old allowance is gone, not merged.
        ->and($subscription->allowanceFor(Format::Recorded))->not->toBeNull()
        ->and($subscription->allowanceFor(Format::Live))->toBeNull();
});

it('does nothing when the plan already matches the billed price', function (): void {
    $plan = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create();
    $subscription = Subscription::factory()->forPlan($plan)->create(['stripe_price' => $plan->stripe_price_id]);
    app(SnapshotPlanEntitlements::class)($subscription, $plan);

    expect(app(ReconcileSubscription::class)($subscription->refresh()))->toBeFalse();
});

it('leaves a subscription whose price it does not recognise', function (): void {
    $plan = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create();
    $subscription = Subscription::factory()->forPlan($plan)->create(['stripe_price' => 'price_unknown_to_us']);

    expect(app(ReconcileSubscription::class)($subscription))->toBeFalse();
});

it('reconciles drift through the scheduled sweep', function (): void {
    [$subscription, , $planB] = driftedSubscription();

    $this->artisan('memberships:reconcile')
        ->expectsOutputToContain('1 subscription(s) reconciled.')
        ->assertSuccessful();

    expect((int) $subscription->refresh()->membership_plan_id)->toBe($planB->getKey());
});

it('reconciles drift from a Cashier subscription webhook', function (): void {
    [$subscription, , $planB] = driftedSubscription();

    event(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => $subscription->stripe_id]],
    ]));

    expect((int) $subscription->refresh()->membership_plan_id)->toBe($planB->getKey());
});

it('ignores a webhook for a subscription it does not hold', function (): void {
    event(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => 'sub_not_here']],
    ]));

    // No subscriptions at all, and nothing threw.
    expect(Subscription::query()->count())->toBe(0);
});

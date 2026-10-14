<?php

declare(strict_types=1);

use App\Actions\Memberships\CancelSubscription;
use App\Actions\Memberships\ChangePlan;
use App\Actions\Memberships\ResumeSubscription;
use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;

/*
 * Self-service management — §6 / P4 slice 3. Cancel/resume/change end in Cashier
 * calls that need Stripe and are verified there; these cover the page, the
 * ownership resolution and the guards that run before any Stripe call.
 */

function memberWithPlan(array $planAttributes = []): array
{
    $user = User::factory()->create();
    $plan = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create($planAttributes);
    $subscription = Subscription::factory()->for($user)->forPlan($plan)->create(['stripe_price' => $plan->stripe_price_id]);
    app(SnapshotPlanEntitlements::class)($subscription, $plan);

    return [$user, $plan, $subscription->refresh()];
}

// The manage page --------------------------------------------------------------

it('requires a signed-in user', function (): void {
    $this->get(route('membership.manage'))->assertRedirect(route('login'));
});

it('sends a member with no subscription to the pricing page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('membership.manage'))
        ->assertRedirect(route('membership.index'));
});

it('shows the plan, allowance and a cancel option to an active member', function (): void {
    [$user, $plan] = memberWithPlan(['name' => 'Professional']);

    $this->actingAs($user)
        ->get(route('membership.manage'))
        ->assertSuccessful()
        ->assertSee('Professional')
        ->assertSee($plan->name)
        // "4 of 4 left" for the live allowance, unspent.
        ->assertSee('4 of 4 left')
        ->assertSee(route('membership.cancel'), escape: false);
});

it('shows resume rather than cancel to a member on their grace period', function (): void {
    $user = User::factory()->create();
    $plan = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create();
    Subscription::factory()->for($user)->forPlan($plan)->onGracePeriod()->create();

    $this->actingAs($user)
        ->get(route('membership.manage'))
        ->assertSuccessful()
        ->assertSee(route('membership.resume'), escape: false)
        ->assertDontSee(route('membership.cancel'), escape: false);
});

// Endpoint guards (resolve the member's own subscription; fail before Stripe) --

it('flashes an error when resuming a membership that is not ending', function (): void {
    [$user] = memberWithPlan(); // active, not on grace

    $this->actingAs($user)
        ->post(route('membership.resume'))
        ->assertRedirect(route('membership.manage'))
        ->assertSessionHas('membershipError');
});

it('flashes an error when cancelling one already set to end', function (): void {
    $user = User::factory()->create();
    $plan = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create();
    Subscription::factory()->for($user)->forPlan($plan)->canceled()->create();

    $this->actingAs($user)
        ->post(route('membership.cancel'))
        ->assertRedirect(route('membership.manage'))
        ->assertSessionHas('membershipError');
});

it('rejects a change to an unrecognised plan', function (): void {
    [$user] = memberWithPlan();

    $this->actingAs($user)
        ->post(route('membership.change'), ['plan' => 'no-such-plan'])
        ->assertRedirect(route('membership.manage'))
        ->assertSessionHas('membershipError', 'That plan was not recognised.');
});

// The action guards, directly --------------------------------------------------

it('refuses to cancel an already-cancelled subscription', function (): void {
    $sub = Subscription::factory()->canceled()->create();

    expect(fn () => app(CancelSubscription::class)($sub))
        ->toThrow(RuntimeException::class, 'already set to end');
});

it('refuses to resume one that is not on grace', function (): void {
    $sub = Subscription::factory()->create(); // active

    expect(fn () => app(ResumeSubscription::class)($sub))
        ->toThrow(RuntimeException::class, 'nothing to resume');
});

it('refuses a plan change to the plan already held', function (): void {
    $plan = MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])->create();
    $sub = Subscription::factory()->forPlan($plan)->create(['stripe_price' => $plan->stripe_price_id]);

    expect(fn () => app(ChangePlan::class)($sub, $plan))
        ->toThrow(RuntimeException::class, 'already on that plan');
});

it('refuses a plan change to a non-recurring plan', function (): void {
    $sub = Subscription::factory()->create();
    $oneOff = MembershipPlan::factory()->oneTime()->granting([Format::Live->value => 4])->create();

    expect(fn () => app(ChangePlan::class)($sub, $oneOff))
        ->toThrow(RuntimeException::class, 'cannot be subscribed');
});

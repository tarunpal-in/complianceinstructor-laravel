<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\SubscriptionState;
use App\Enums\UserRole;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SubscriptionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A subscriber on a plan, with the snapshot a real signup would have taken. */
function subscriber(array $allowances = [], array $attributes = []): Subscription
{
    $plan = MembershipPlan::factory()
        ->granting($allowances === [] ? [Format::Live->value => 4] : $allowances)
        ->create();

    $subscription = Subscription::factory()->forPlan($plan)->create($attributes);

    app(App\Actions\Memberships\SnapshotPlanEntitlements::class)($subscription);

    return $subscription->fresh(['entitlements', 'plan.entitlements', 'member']);
}

// Authorization ---------------------------------------------------------------

it('opens subscriptions to roles that may read them', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/subscriptions')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance, UserRole::Support]);

/** A membership is a payment record, and the Content Editor has no access to money. */
it('keeps the Content Editor out of subscriptions', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/subscriptions')
        ->assertForbidden();
});

/**
 * §3 gives Cashier the lifecycle, so there is nothing here to create or edit —
 * a subscription begins with a payment method, which is a customer's action.
 */
it('offers no way to create, edit or delete a subscription', function (): void {
    $subscription = subscriber();

    expect(App\Filament\Resources\Subscriptions\SubscriptionResource::canCreate())->toBeFalse()
        ->and(App\Filament\Resources\Subscriptions\SubscriptionResource::canEdit($subscription))->toBeFalse()
        ->and(App\Filament\Resources\Subscriptions\SubscriptionResource::canDelete($subscription))->toBeFalse();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/subscriptions/create')
        ->assertNotFound();
});

// The state ------------------------------------------------------------------

it('reads a state the client recognises', function (array $attributes, SubscriptionState $expected): void {
    $subscription = Subscription::factory()->create($attributes);

    expect($subscription->state())->toBe($expected);
})->with([
    'active' => [['stripe_status' => 'active'], SubscriptionState::Active],
    'past due' => [['stripe_status' => 'past_due'], SubscriptionState::PastDue],
    'unpaid' => [['stripe_status' => 'unpaid'], SubscriptionState::Unpaid],
    'incomplete' => [['stripe_status' => 'incomplete'], SubscriptionState::Incomplete],
    'trialing' => [['stripe_status' => 'trialing', 'trial_ends_at' => now()->addWeek()], SubscriptionState::Trialing],
]);

/**
 * The two cases Stripe's own column cannot express, and the two the client asks
 * about most. Cancelled-but-paid-up is neither "active" nor "cancelled".
 */
it('separates a membership that is ending from one that has ended', function (): void {
    $ending = Subscription::factory()->create(['ends_at' => now()->addWeek()]);
    $ended = Subscription::factory()->create(['ends_at' => now()->subDay()]);

    expect($ending->state())->toBe(SubscriptionState::Ending)
        ->and($ending->state()->grantsAccess())->toBeTrue()
        ->and($ended->state())->toBe(SubscriptionState::Ended)
        ->and($ended->state()->grantsAccess())->toBeFalse();
});

/** A status Stripe adds later must say so rather than be filed as something plausible. */
it('says when it does not recognise a status', function (): void {
    $subscription = Subscription::factory()->create(['stripe_status' => 'something_new']);

    expect($subscription->state())->toBe(SubscriptionState::Unknown)
        ->and($subscription->state()->grantsAccess())->toBeFalse();
});

it('filters by state through Cashier own scopes', function (): void {
    $active = Subscription::factory()->create();
    $ending = Subscription::factory()->onGracePeriod()->create();
    $ended = Subscription::factory()->canceled()->create();
    $pastDue = Subscription::factory()->pastDue()->create();

    $inState = fn (SubscriptionState $state): array => Subscription::query()
        ->inState($state)
        ->pluck('id')
        ->all();

    expect($inState(SubscriptionState::Active))->toBe([$active->id])
        ->and($inState(SubscriptionState::Ending))->toBe([$ending->id])
        ->and($inState(SubscriptionState::Ended))->toBe([$ended->id])
        ->and($inState(SubscriptionState::PastDue))->toBe([$pastDue->id]);
});

// Terms -----------------------------------------------------------------------

it('shows a member the terms they were sold, not the plan today', function (): void {
    $subscription = subscriber([Format::Live->value => 4]);

    $subscription->plan?->entitlements()->where('format', Format::Live)->update(['quantity' => 2]);

    $subscription = $subscription->fresh(['entitlements', 'plan.entitlements']);

    expect($subscription?->allowanceLines())->toBe(['4 live webinars per term'])
        ->and($subscription?->termsDifferFromPlan())->toBeTrue();
});

/**
 * The filter support actually wants after editing a plan: who is on old terms.
 * Answered in the database so the list can paginate.
 */
it('finds every member whose terms no longer match their plan', function (): void {
    $matching = subscriber([Format::Live->value => 4]);
    $altered = subscriber([Format::Live->value => 4]);

    $altered->entitlements()->where('format', Format::Live)->update(['quantity' => 9]);

    $ids = Subscription::idsWithAlteredTerms()->pluck('id')->all();

    expect($ids)->toContain($altered->id)
        ->and($ids)->not->toContain($matching->id);
});

/** NULL means unlimited, and `NULL = NULL` is not true in SQL. */
it('does not read an unlimited allowance as a difference', function (): void {
    $subscription = subscriber([Format::Transcript->value => null]);

    expect(Subscription::idsWithAlteredTerms()->pluck('id')->all())->not->toContain($subscription->id)
        ->and($subscription->termsDifferFromPlan())->toBeFalse();
});

/** A format on one side and not the other is a difference in both directions. */
it('notices a format added to or missing from the plan', function (): void {
    $missingFromMember = subscriber([Format::Live->value => 4]);
    $missingFromMember->entitlements()->delete();

    $extraOnMember = subscriber([Format::Live->value => 4]);
    SubscriptionEntitlement::factory()->format(Format::Download)->create([
        'subscription_id' => $extraOnMember->getKey(),
    ]);

    $ids = Subscription::idsWithAlteredTerms()->pluck('id')->all();

    expect($ids)->toContain($missingFromMember->id)
        ->and($ids)->toContain($extraOnMember->id);
});

// The list --------------------------------------------------------------------

it('separates who is paying from who is leaving', function (): void {
    $active = Subscription::factory()->create();
    $ending = Subscription::factory()->onGracePeriod()->create();
    $pastDue = Subscription::factory()->pastDue()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListSubscriptions::class)
        ->set('activeTab', 'ending')
        ->assertCanSeeTableRecords([$ending])
        ->assertCanNotSeeTableRecords([$active, $pastDue])
        ->set('activeTab', 'problems')
        ->assertCanSeeTableRecords([$pastDue])
        ->assertCanNotSeeTableRecords([$active, $ending]);
});

it('lists each allowance separately so the row cannot grow sideways', function (): void {
    $subscription = subscriber([
        Format::Download->value => 3,
        Format::Live->value => 3,
    ]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListSubscriptions::class)
        // Sorted by format, so the column cannot reshuffle between page loads.
        ->assertTableColumnStateSet('allowances', [
            '3 live webinars per term',
            '3 downloads per term',
        ], $subscription);
});

// Correcting a member's terms -------------------------------------------------

it('replaces a member allowances with the plan on request', function (): void {
    $subscription = subscriber([Format::Live->value => 4]);

    $subscription->plan?->entitlements()->where('format', Format::Live)->update(['quantity' => 2]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ViewSubscription::class, ['record' => $subscription->getKey()])
        ->callAction('reapplyPlanTerms');

    expect($subscription->fresh(['entitlements'])?->entitlements->first()?->quantity)->toBe(2);
});

/** Support may read a membership and may not rewrite what somebody was sold. */
it('keeps the re-apply action away from Support', function (): void {
    $subscription = subscriber([Format::Live->value => 4]);
    $subscription->plan?->entitlements()->where('format', Format::Live)->update(['quantity' => 2]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ViewSubscription::class, ['record' => $subscription->getKey()])
        ->assertActionHidden('reapplyPlanTerms');
});

/** Nothing to correct, nothing offered. */
it('hides the re-apply action when the terms already match', function (): void {
    $subscription = subscriber([Format::Live->value => 4]);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ViewSubscription::class, ['record' => $subscription->getKey()])
        ->assertActionHidden('reapplyPlanTerms');
});

/**
 * Cancelling reaches Stripe, so without a secret the action is shown as
 * unavailable rather than offered and then failing in front of the client.
 */
it('disables the Stripe actions when Stripe is not configured', function (): void {
    config()->set('services.stripe.secret', null);

    $subscription = subscriber();

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(ViewSubscription::class, ['record' => $subscription->getKey()])
        ->assertActionDisabled('cancel')
        ->assertActionDisabled('cancelNow');
});

it('offers no cancellation to a role that cannot manage subscriptions', function (): void {
    config()->set('services.stripe.secret', 'sk_test_seeded');

    $subscription = subscriber();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ViewSubscription::class, ['record' => $subscription->getKey()])
        ->assertActionHidden('cancel')
        ->assertActionHidden('cancelNow');
});

// Seed data is a deliverable (§5.9) -------------------------------------------

it('seeds a few mid-term memberships in every state the screen renders', function (): void {
    $this->seed(MembershipPlanSeeder::class);
    $this->seed(SubscriptionSeeder::class);

    $states = Subscription::query()->with('entitlements')->get()
        ->map(fn (Subscription $subscription): SubscriptionState => $subscription->state());

    expect(Subscription::query()->count())->toBe(5)
        ->and($states)->toContain(SubscriptionState::Active)
        ->and($states)->toContain(SubscriptionState::Ending)
        ->and($states)->toContain(SubscriptionState::PastDue)
        ->and($states)->toContain(SubscriptionState::Ended)
        // Every one snapshotted, because that is what a real signup does.
        ->and(SubscriptionEntitlement::query()->count())->toBeGreaterThan(0)
        // And one left on old terms, so the badge and filter are reachable.
        ->and(Subscription::idsWithAlteredTerms()->count())->toBe(1);
});

it('seeds subscriptions idempotently', function (): void {
    $this->seed(MembershipPlanSeeder::class);
    $this->seed(SubscriptionSeeder::class);
    $this->seed(SubscriptionSeeder::class);

    expect(Subscription::query()->count())->toBe(5);
});

it('skips subscriptions when there are no plans to subscribe to', function (): void {
    $this->seed(SubscriptionSeeder::class);

    expect(Subscription::query()->count())->toBe(0);
});

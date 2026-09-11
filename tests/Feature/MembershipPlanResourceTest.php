<?php

declare(strict_types=1);

use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Enums\AllowanceWindow;
use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Enums\UserRole;
use App\Filament\Resources\MembershipPlans\Pages\CreateMembershipPlan;
use App\Filament\Resources\MembershipPlans\Pages\EditMembershipPlan;
use App\Filament\Resources\MembershipPlans\Pages\ListMembershipPlans;
use App\Models\MembershipPlan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A sellable yearly plan granting four live sessions a term. */
function plan(array $attributes = []): MembershipPlan
{
    return MembershipPlan::factory()
        ->granting([Format::Live->value => 4])
        ->create($attributes)
        ->load('entitlements');
}

// Authorization ---------------------------------------------------------------

it('opens plans to roles that may read them', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/membership-plans')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Finance]);

it('keeps the Content Editor and Support out of plans', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/membership-plans')
        ->assertForbidden();
})->with([UserRole::ContentEditor, UserRole::Support]);

/**
 * A plan is a price. Finance reads every one of them and cannot change what a
 * tier costs — that is the Super Admin's, per the same reasoning that withholds
 * `refund_payment`.
 */
it('lets Finance read a plan without editing it', function (): void {
    $plan = plan();

    $this->actingAs(userWithRole(UserRole::Finance))
        ->get('/admin/membership-plans/'.$plan->getKey().'/edit')
        ->assertForbidden();
});

// The model -------------------------------------------------------------------

it('reads an allowance in the words the client uses', function (): void {
    $counted = PlanEntitlement::factory()->make([
        'format' => Format::Live,
        'quantity' => 4,
        'window' => AllowanceWindow::Month,
    ]);

    $unlimited = PlanEntitlement::factory()->unlimited()->make(['format' => Format::Transcript]);

    expect($counted->describe())->toBe('4 live webinars per month')
        ->and($unlimited->describe())->toBe('unlimited transcripts')
        ->and($unlimited->isUnlimited())->toBeTrue()
        ->and($counted->isUnlimited())->toBeFalse();
});

/**
 * §5.5 — NULL means unlimited, never 0 and never -1, because both are numbers
 * arithmetic will happily use. remainingAfter() is where P4 will ask.
 */
it('never runs out of an unlimited allowance', function (): void {
    $unlimited = PlanEntitlement::factory()->unlimited()->make();
    $counted = PlanEntitlement::factory()->make(['quantity' => 4]);

    expect($unlimited->remainingAfter(1000))->toBeNull()
        ->and($counted->remainingAfter(1))->toBe(3)
        ->and($counted->remainingAfter(4))->toBe(0)
        // Never negative: an over-consumed allowance is a bug to find, not a
        // debt to carry into the next window.
        ->and($counted->remainingAfter(9))->toBe(0);
});

/**
 * The list column takes a list, not the joined sentence. Handed one long
 * string the cell wraps it wherever the column edge falls, which turned three
 * allowances into a dozen ragged lines and pushed the table into a horizontal
 * scroll.
 */
it('gives the list one entry per allowance', function (): void {
    // Created out of order on purpose: nothing orders the table, so the list
    // has to impose one or the column reshuffles between page loads.
    $plan = MembershipPlan::factory()
        ->granting([
            Format::Download->value => null,
            Format::Recorded->value => 3,
            Format::Live->value => 3,
        ])
        ->create()
        ->load('entitlements');

    expect($plan->allowanceLines())->toBe([
        '3 live webinars per term',
        '3 recordings per term',
        'unlimited downloads',
    ]);

    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(ListMembershipPlans::class)
        ->assertTableColumnStateSet('allowances', $plan->allowanceLines(), $plan);
});

it('summarises a whole tier in one line', function (): void {
    $plan = MembershipPlan::factory()
        ->granting([Format::Live->value => 4])
        ->create();

    $plan->entitlements()->create([
        'format' => Format::Transcript,
        'quantity' => null,
        'window' => AllowanceWindow::Term,
    ]);

    expect($plan->load('entitlements')->describeAllowances())
        ->toBe('4 live webinars per term · unlimited transcripts');
});

/**
 * The constraint §5.5 does not mention and the whole design depends on: two
 * `live` rows would silently double the allowance — the same shape of defect
 * `offering_format` and `order_item_format` each carry this constraint against.
 */
it('refuses a second row for the same format on one plan', function (): void {
    $plan = plan();

    expect(fn () => $plan->entitlements()->create([
        'format' => Format::Live,
        'quantity' => 1,
        'window' => AllowanceWindow::Term,
    ]))->toThrow(QueryException::class);
});

it('says why a plan cannot be sold', function (): void {
    $noStripe = MembershipPlan::factory()
        ->granting([Format::Live->value => 2])
        ->create(['stripe_price_id' => null]);

    expect($noStripe->load('entitlements')->problems())
        ->toContain('A recurring plan needs a Stripe price ID before anyone can subscribe.')
        ->and($noStripe->isSellable())->toBeFalse();

    $grantsNothing = MembershipPlan::factory()->create();

    expect($grantsNothing->load('entitlements')->problems())
        ->toContain('It grants nothing, so a subscriber would pay for no access.');

    // A one-off with no span grants access forever.
    $forever = MembershipPlan::factory()
        ->granting([Format::Recorded->value => 5])
        ->create([
            'billing_period' => BillingPeriod::OneTime,
            'duration_months' => null,
            'stripe_price_id' => null,
        ]);

    expect($forever->load('entitlements')->problems())
        ->toContain('A one-off plan needs a duration, or access never ends.');

    // The two fields would be telling the customer different things.
    $contradictory = MembershipPlan::factory()
        ->granting([Format::Live->value => 1])
        ->create(['billing_period' => BillingPeriod::Year, 'duration_months' => 12]);

    expect($contradictory->load('entitlements')->problems())
        ->toContain('A recurring plan renews on its billing period, so it must not also set a duration.');

    expect(plan()->isSellable())->toBeTrue();
});

// The snapshot ----------------------------------------------------------------

/**
 * Agreed 2026-09-11: an active subscriber keeps the terms they signed up under.
 * This is the test that says so.
 */
it('freezes what a subscriber was sold', function (): void {
    $plan = plan(['price_cents' => 19900]);

    $subscription = Subscription::factory()->forPlan($plan)->create();

    app(SnapshotPlanEntitlements::class)($subscription);

    // The plan is retuned afterwards — halved, and a format taken away.
    $plan->entitlements()->where('format', Format::Live)->update(['quantity' => 2]);
    $plan->update(['price_cents' => 29900]);

    $subscription->refresh()->load('entitlements');

    expect($subscription->entitlements)->toHaveCount(1)
        ->and($subscription->entitlements->first()?->quantity)->toBe(4)
        ->and($subscription->price_cents)->toBe(19900)
        ->and($subscription->describeAllowances())->toBe('4 live webinars per term');
});

it('says when a subscriber is on different terms from the plan', function (): void {
    $plan = plan();

    $subscription = Subscription::factory()->forPlan($plan)->create();

    app(SnapshotPlanEntitlements::class)($subscription);

    expect($subscription->load('plan.entitlements')->termsDifferFromPlan())->toBeFalse();

    $plan->entitlements()->where('format', Format::Live)->update(['quantity' => 2]);

    expect($subscription->fresh(['entitlements', 'plan.entitlements'])?->termsDifferFromPlan())->toBeTrue();
});

/**
 * A re-snapshot happens on a plan change. Merging would leave behind an
 * allowance for a format the new plan does not grant, which reads as access the
 * customer never bought.
 */
it('replaces the snapshot rather than adding to it', function (): void {
    $old = plan();
    $new = MembershipPlan::factory()
        ->granting([Format::Recorded->value => null])
        ->create()
        ->load('entitlements');

    $subscription = Subscription::factory()->forPlan($old)->create();

    app(SnapshotPlanEntitlements::class)($subscription);
    app(SnapshotPlanEntitlements::class)($subscription, $new);

    $subscription->refresh()->load('entitlements');

    expect(SubscriptionEntitlement::query()->count())->toBe(1)
        ->and($subscription->entitlements->first()?->format)->toBe(Format::Recorded)
        ->and($subscription->entitlements->first()?->isUnlimited())->toBeTrue()
        ->and($subscription->membership_plan_id)->toBe($new->getKey());
});

it('does nothing for a subscription with no plan', function (): void {
    $subscription = Subscription::factory()->create(['membership_plan_id' => null]);

    expect(app(SnapshotPlanEntitlements::class)($subscription))->toBeEmpty()
        ->and(SubscriptionEntitlement::query()->count())->toBe(0);
});

// The form --------------------------------------------------------------------

it('stores a typed price as cents and an unlimited allowance as null', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateMembershipPlan::class)
        ->fillForm([
            'name' => 'Professional',
            'slug' => 'professional',
            'price' => '999.00',
            'billing_period' => BillingPeriod::Year->value,
            'stripe_price_id' => 'price_test_professional',
            'is_active' => true,
            'entitlements' => [
                ['format' => Format::Live->value, 'unlimited' => false, 'quantity' => 4, 'window' => AllowanceWindow::Month->value],
                ['format' => Format::Transcript->value, 'unlimited' => true, 'window' => AllowanceWindow::Term->value],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = MembershipPlan::query()->where('slug', 'professional')->firstOrFail()->load('entitlements');

    expect($plan->price_cents)->toBe(99900)
        ->and($plan->duration_months)->toBeNull()
        ->and($plan->entitlements)->toHaveCount(2)
        ->and($plan->describeAllowances())->toBe('4 live webinars per month · unlimited transcripts');
});

/** A one-off has a duration and no Stripe price; a recurring plan the reverse. */
it('keeps the billing period and the duration from contradicting each other', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateMembershipPlan::class)
        ->fillForm([
            'name' => 'Annual Pass',
            'slug' => 'annual-pass',
            'price' => '799.00',
            'billing_period' => BillingPeriod::OneTime->value,
            'duration_months' => 12,
            'is_active' => true,
            'entitlements' => [
                ['format' => Format::Recorded->value, 'unlimited' => false, 'quantity' => 12, 'window' => AllowanceWindow::Term->value],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = MembershipPlan::query()->where('slug', 'annual-pass')->firstOrFail();

    expect($plan->duration_months)->toBe(12)
        ->and($plan->stripe_price_id)->toBeNull()
        ->and($plan->load('entitlements')->isSellable())->toBeTrue();
});

/**
 * The guard that matters commercially: a plan on the pricing page that cannot
 * be paid for fails at the moment a customer tries, which is the worst possible
 * time to find out.
 */
it('refuses to put an unsellable plan on the pricing page', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateMembershipPlan::class)
        ->fillForm([
            'name' => 'Half Finished',
            'slug' => 'half-finished',
            'price' => '199.00',
            'billing_period' => BillingPeriod::Year->value,
            'stripe_price_id' => null,
            'is_active' => true,
            'entitlements' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['is_active']);

    expect(MembershipPlan::query()->where('slug', 'half-finished')->exists())->toBeFalse();
});

it('allows the same plan to be saved while it is off the pricing page', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateMembershipPlan::class)
        ->fillForm([
            'name' => 'Half Finished',
            'slug' => 'half-finished',
            'price' => '199.00',
            'billing_period' => BillingPeriod::Year->value,
            'stripe_price_id' => null,
            'is_active' => false,
            'entitlements' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MembershipPlan::query()->where('slug', 'half-finished')->exists())->toBeTrue();
});

it('loads an existing plan back into the form with the unlimited toggle set', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $plan = MembershipPlan::factory()
        ->granting([Format::Transcript->value => null])
        ->create();

    Livewire\Livewire::test(EditMembershipPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        // Cents come back as the decimal the client typed, and the unlimited
        // row comes back with its toggle on rather than a blank quantity.
        ->assertFormSet([
            'price' => App\Support\Money::toDecimal($plan->price_cents),
        ]);
});

// The list --------------------------------------------------------------------

it('counts only the members who are actually subscribed', function (): void {
    $plan = plan();

    Subscription::factory()->forPlan($plan)->count(2)->create();
    Subscription::factory()->forPlan($plan)->canceled()->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/membership-plans')
        ->assertSuccessful();

    $counted = MembershipPlan::query()
        ->withCount(['subscriptions as active_subscribers_count' => fn ($query) => $query->active()])
        ->findOrFail($plan->getKey());

    expect($counted->active_subscribers_count)->toBe(2);
});

/** Each relation counted once — the alias appears twice and MySQL rejects it. */
it('counts subscribers once in the plans query', function (): void {
    $sql = App\Filament\Resources\MembershipPlans\MembershipPlanResource::getEloquentQuery()->toSql();

    expect(substr_count($sql, 'active_subscribers_count'))->toBeLessThanOrEqual(1);
});

it('will not delete a plan people are still paying for', function (): void {
    $plan = plan();

    Subscription::factory()->forPlan($plan)->create();

    expect($plan->deletionBlockReason())->toContain('1 active subscription');

    $empty = plan();

    expect($empty->deletionBlockReason())->toBeNull();
});

// Deleting -------------------------------------------------------------------

/**
 * The bug this asserts against: the resource query strips the soft-delete
 * scope so a deleted plan can still be opened and restored, and for a while
 * nothing put the scope back. A deleted plan stayed in the list looking live,
 * so the delete reported success and appeared to do nothing.
 */
it('takes a deleted plan out of the list', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $kept = plan(['name' => 'Kept']);
    $deleted = plan(['name' => 'Deleted']);

    Livewire\Livewire::test(ListMembershipPlans::class)
        ->assertCanSeeTableRecords([$kept, $deleted]);

    $deleted->delete();

    Livewire\Livewire::test(ListMembershipPlans::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$deleted])
        // Still reachable, which is why the scope is stripped in the first
        // place: the filter is what hides it, not the query.
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$kept, $deleted]);
});

it('keeps a deleted plan off the pricing page', function (): void {
    $plan = plan(['name' => 'Withdrawn', 'is_active' => true]);

    $this->get('/')->assertSee('Withdrawn', escape: false);

    $plan->delete();

    $this->get('/')->assertDontSee('Withdrawn', escape: false);
});

/**
 * The reported bug: a UNIQUE index counts soft-deleted rows, so a deleted
 * "Regular" plan kept `regular` reserved against a record no screen would show.
 */
it('hands the slug back when a plan is deleted', function (): void {
    $plan = plan(['slug' => 'regular']);

    $plan->delete();

    expect($plan->fresh()?->slug)->toBe('regular-deleted-'.$plan->getKey());

    // Which is the point: the name is free for something else to take.
    $replacement = plan(['slug' => 'regular']);

    expect($replacement->slug)->toBe('regular');
});

it('takes the slug back on restore when it is still free', function (): void {
    $plan = plan(['slug' => 'regular']);

    $plan->delete();
    $plan->restore();

    expect($plan->fresh()?->slug)->toBe('regular');
});

/** Somebody took the name in the meantime; the restored plan keeps its suffix. */
it('does not steal a slug back from a live plan', function (): void {
    $plan = plan(['slug' => 'regular']);
    $plan->delete();

    plan(['slug' => 'regular']);

    $plan->restore();

    expect($plan->fresh()?->slug)->toBe('regular-deleted-'.$plan->getKey());
});

it('leaves nothing behind when a plan is force deleted', function (): void {
    $plan = plan(['slug' => 'regular']);

    $plan->forceDelete();

    expect(MembershipPlan::withTrashed()->count())->toBe(0)
        ->and(plan(['slug' => 'regular'])->slug)->toBe('regular');
});

it('puts a deleted plan back', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $plan = plan();
    $plan->delete();

    Livewire\Livewire::test(EditMembershipPlan::class, ['record' => $plan->getKey()])
        ->callAction('restore');

    expect($plan->fresh()?->trashed())->toBeFalse();
});

// Seed data is a deliverable (§5.9) -------------------------------------------

it('seeds the four tiers from the comp', function (): void {
    $this->seed(MembershipPlanSeeder::class);

    $plans = MembershipPlan::query()->with('entitlements')->orderBy('sort_order')->get();

    expect($plans)->toHaveCount(4)
        ->and($plans->pluck('name')->all())->toBe(['Regular', 'Plus', 'Standard', 'Business'])
        ->and($plans->pluck('price_cents')->all())->toBe([29900, 49900, 79900, 99900])
        ->and($plans->pluck('duration_months')->all())->toBe([3, 5, 7, 12])
        ->and($plans->where('is_featured', true))->toHaveCount(1)
        // Every one of them is sellable, so seeding cannot produce a pricing
        // page nobody can buy from.
        ->and($plans->reject->isSellable())->toBeEmpty()
        // At least one unlimited allowance and one counted one.
        ->and($plans->flatMap->entitlements->filter->isUnlimited())->not->toBeEmpty()
        ->and($plans->flatMap->entitlements->reject->isUnlimited())->not->toBeEmpty();
});

/** Re-running must not double an allowance — the UNIQUE would refuse it. */
it('seeds plans idempotently', function (): void {
    $this->seed(MembershipPlanSeeder::class);
    $this->seed(MembershipPlanSeeder::class);

    expect(MembershipPlan::query()->count())->toBe(4)
        ->and(PlanEntitlement::query()->count())->toBe(15);
});

// The pricing card ------------------------------------------------------------

it('reads a price and a term the way a pricing card does', function (): void {
    $oneOff = MembershipPlan::factory()->oneTime(3)->make(['price_cents' => 29900]);
    $monthly = MembershipPlan::factory()->monthly()->make(['price_cents' => 4900]);
    $single = MembershipPlan::factory()->oneTime(1)->make(['price_cents' => 9900]);

    expect($oneOff->cardPrice())->toBe('$299')
        ->and($oneOff->describeTerm())->toBe('3 months')
        ->and($monthly->describeTerm())->toBe('month')
        ->and($single->describeTerm())->toBe('1 month');
});

/** The badge is the quota, so it comes from the entitlements and nowhere else. */
it('derives the card badge from what the plan grants', function (): void {
    $counted = MembershipPlan::factory()
        ->granting([Format::Live->value => 3, Format::Recorded->value => 3])
        ->create()
        ->load('entitlements');

    $unlimited = MembershipPlan::factory()
        ->granting([Format::Live->value => null])
        ->create()
        ->load('entitlements');

    // No live allowance at all: the first row leads rather than nothing.
    $transcriptsOnly = MembershipPlan::factory()
        ->granting([Format::Transcript->value => 6])
        ->create()
        ->load('entitlements');

    expect($counted->describeHeadline())->toBe('3 Live Webinars')
        ->and($unlimited->describeHeadline())->toBe('Unlimited Live Webinars')
        ->and($transcriptsOnly->describeHeadline())->toBe('6 Transcripts')
        ->and(MembershipPlan::factory()->create()->load('entitlements')->describeHeadline())->toBeNull();
});

/**
 * `highlights` is a JSON column the client edits, so a malformed row must cost
 * a missing bullet rather than the homepage.
 */
it('renders only the highlight rows it can read', function (): void {
    $plan = MembershipPlan::factory()->make([
        'highlights' => [
            ['label' => 'Certificate of Completion', 'included' => true],
            ['label' => 'E-Transcript Access', 'included' => false],
            // Absent toggle reads as included; the rest are unusable.
            ['label' => 'PDF Study Materials'],
            ['included' => true],
            'not an array',
        ],
    ]);

    expect($plan->highlightRows())->toBe([
        ['label' => 'Certificate of Completion', 'included' => true],
        ['label' => 'E-Transcript Access', 'included' => false],
        ['label' => 'PDF Study Materials', 'included' => true],
    ]);

    expect(MembershipPlan::factory()->make(['highlights' => null])->highlightRows())->toBe([]);
});

it('keeps the card copy through the form', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateMembershipPlan::class)
        ->fillForm([
            'name' => 'Regular',
            'slug' => 'regular',
            'price' => '299.00',
            'billing_period' => BillingPeriod::OneTime->value,
            'duration_months' => 3,
            'is_active' => true,
            'entitlements' => [
                ['format' => Format::Live->value, 'unlimited' => false, 'quantity' => 3, 'window' => AllowanceWindow::Term->value],
            ],
            'highlights' => [
                ['label' => 'Certificate of Completion', 'included' => true],
                ['label' => 'E-Transcript Access', 'included' => false],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = MembershipPlan::query()->where('slug', 'regular')->firstOrFail();

    expect($plan->highlightRows())->toBe([
        ['label' => 'Certificate of Completion', 'included' => true],
        ['label' => 'E-Transcript Access', 'included' => false],
    ]);
});

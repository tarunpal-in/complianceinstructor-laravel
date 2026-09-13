<?php

declare(strict_types=1);

use App\Actions\Entitlements\GrantEntitlement;
use App\Actions\Entitlements\RevokeEntitlement;
use App\Actions\Memberships\AdjustSubscriptionAllowance;
use App\Actions\Memberships\RedeemMembershipAllowance;
use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Enums\AllowanceWindow;
use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\EntitlementsRelationManager as AllowancesManager;
use App\Filament\Resources\Subscriptions\RelationManagers\RedemptionsRelationManager as RedemptionsManager;
use App\Models\Entitlement;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A member on a plan, snapshotted the way a real signup snapshots. */
function member(array $allowances = [], AllowanceWindow $window = AllowanceWindow::Term): Subscription
{
    $plan = MembershipPlan::factory()
        ->oneTime(12)
        ->granting($allowances === [] ? [Format::Live->value => 3] : $allowances, $window)
        ->create();

    $subscription = Subscription::factory()->forPlan($plan)->create();

    app(SnapshotPlanEntitlements::class)($subscription);

    return $subscription->fresh(['entitlements', 'plan.entitlements', 'member']);
}

function redeem(Subscription $subscription, ?Webinar $webinar = null, Format $format = Format::Live): Entitlement
{
    return app(RedeemMembershipAllowance::class)(
        $subscription,
        $webinar ?? Webinar::factory()->create(),
        $format,
    );
}

// The access check ------------------------------------------------------------

/**
 * §5.2 — one helper is the only way anything asks about access. §14 #9 bans
 * re-deriving it, which is what let package buyers lose their own recordings.
 */
it('answers whether a person may watch something', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    expect(Entitlement::grants($user, $webinar, Format::Live))->toBeFalse();

    app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Manual);

    expect(Entitlement::grants($user, $webinar, Format::Live))->toBeTrue()
        // A grant is per format, not per webinar.
        ->and(Entitlement::grants($user, $webinar, Format::Recorded))->toBeFalse();
});

it('stops granting access once revoked or expired', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    $revoked = Entitlement::factory()->revoked()->create([
        'user_id' => $user->id, 'webinar_id' => $webinar->id, 'format' => Format::Live,
    ]);

    expect(Entitlement::grants($user, $webinar, Format::Live))->toBeFalse()
        ->and($revoked->isActive())->toBeFalse();

    Entitlement::factory()->expired()->create([
        'user_id' => $user->id, 'webinar_id' => $webinar->id, 'format' => Format::Recorded,
    ]);

    expect(Entitlement::grants($user, $webinar, Format::Recorded))->toBeFalse();
});

/** §14 #5 — a replayed webhook or a double-clicked button must not double-grant. */
it('grants once however many times it is asked', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    $first = app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Order, 7);
    $second = app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Order, 7);

    expect($second->id)->toBe($first->id)
        ->and(Entitlement::query()->count())->toBe(1);
});

/** Re-granting is the same access coming back, not a second copy of it. */
it('restores a revoked grant rather than duplicating it', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    $granted = app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Order, 7);
    app(RevokeEntitlement::class)($granted, 'Refunded');

    app(GrantEntitlement::class)($user, $webinar, Format::Live, EntitlementSource::Order, 7);

    expect(Entitlement::query()->count())->toBe(1)
        ->and(Entitlement::grants($user, $webinar, Format::Live))->toBeTrue();
});

// Spending an allowance -------------------------------------------------------

it('spends one allowance and leaves the rest', function (): void {
    $subscription = member([Format::Live->value => 3]);

    redeem($subscription);

    $subscription = $subscription->fresh(['entitlements']);

    expect($subscription?->usedInWindow(Format::Live))->toBe(1)
        ->and($subscription?->remainingFor(Format::Live))->toBe(2)
        ->and($subscription?->describeRemaining(Format::Live))->toBe('2 of 3 left');
});

/** The predecessor summed all-time purchases, so a quota never actually stopped anything. */
it('refuses once the allowance is spent', function (): void {
    $subscription = member([Format::Live->value => 1]);

    redeem($subscription);

    expect(fn () => redeem($subscription->fresh(['entitlements'])))
        ->toThrow(RuntimeException::class, 'They have used all 1 live webinars');
});

it('never runs out of an unlimited allowance', function (): void {
    $subscription = member([Format::Recorded->value => null]);

    foreach (range(1, 5) as $ignored) {
        redeem($subscription->fresh(['entitlements']), format: Format::Recorded);
    }

    expect($subscription->fresh(['entitlements'])?->remainingFor(Format::Recorded))->toBeNull()
        ->and(Entitlement::query()->count())->toBe(5);
});

it('refuses a format the membership does not include', function (): void {
    $subscription = member([Format::Live->value => 3]);

    expect(fn () => redeem($subscription, format: Format::Download))
        ->toThrow(RuntimeException::class, 'does not include');
});

it('refuses to spend a membership that has ended', function (): void {
    $subscription = member([Format::Live->value => 3]);
    $subscription->forceFill(['term_ends_at' => now()->subDay()])->save();

    expect(fn () => redeem($subscription->fresh(['entitlements'])))
        ->toThrow(RuntimeException::class, 'cannot be spent');
});

/** Access lasts as long as the membership that paid for it, and no longer. */
it('expires a spent session with the membership term', function (): void {
    $subscription = member([Format::Live->value => 3]);

    $entitlement = redeem($subscription);

    expect($entitlement->expires_at?->toDateString())
        ->toBe($subscription->accessEndsAt()?->toDateString());
});

// Giving one back — the support case ------------------------------------------

/**
 * The case this was built for: spent a live session, could not attend, was sent
 * the recording, wants the allowance back.
 */
it('returns the allowance when a session is given back', function (): void {
    $subscription = member([Format::Live->value => 3]);
    $webinar = Webinar::factory()->create();

    $spent = redeem($subscription, $webinar);

    expect($subscription->fresh(['entitlements'])?->remainingFor(Format::Live))->toBe(2);

    app(RevokeEntitlement::class)($spent, 'Could not attend; sent the recording instead');

    $subscription = $subscription->fresh(['entitlements']);

    expect($subscription?->remainingFor(Format::Live))->toBe(3)
        ->and($subscription?->usedInWindow(Format::Live))->toBe(0)
        // And the access itself is gone, not just the count.
        ->and(Entitlement::grants($subscription->member, $webinar, Format::Live))->toBeFalse();
});

it('keeps who gave it back and why', function (): void {
    $staff = userWithRole(App\Enums\UserRole::Support);
    $this->actingAs($staff);

    $spent = redeem(member([Format::Live->value => 3]));

    app(RevokeEntitlement::class)($spent, 'Could not attend');

    expect($spent->fresh()?->note)->toBe('Could not attend')
        ->and($spent->fresh()?->revoked_by)->toBe($staff->id);
});

it('refuses to give back a session twice', function (): void {
    $spent = redeem(member([Format::Live->value => 3]));

    app(RevokeEntitlement::class)($spent, 'Could not attend');

    expect(fn () => app(RevokeEntitlement::class)($spent, 'Again'))
        ->toThrow(RuntimeException::class, 'already been given back');
});

it('insists on a reason', function (): void {
    $spent = redeem(member([Format::Live->value => 3]));

    expect(fn () => app(RevokeEntitlement::class)($spent, '  '))
        ->toThrow(RuntimeException::class, 'Give a reason');
});

/**
 * The trap in the support case: the replacement recording must not come out of
 * the recorded allowance, or fixing one complaint creates the next.
 */
it('does not charge a staff comp against the quota', function (): void {
    $subscription = member([Format::Live->value => 3, Format::Recorded->value => 2]);

    app(GrantEntitlement::class)(
        $subscription->member,
        Webinar::factory()->create(),
        Format::Recorded,
        EntitlementSource::Manual,
    );

    expect($subscription->fresh(['entitlements'])?->remainingFor(Format::Recorded))->toBe(2)
        ->and(EntitlementSource::Manual->consumesAllowance())->toBeFalse();
});

// Windows ---------------------------------------------------------------------

/** "4 per month" on a yearly membership is 48 a year; "4 per term" is 4. */
it('counts a monthly allowance only within the current month', function (): void {
    $subscription = member([Format::Live->value => 2], AllowanceWindow::Month);
    $subscription->forceFill(['term_starts_at' => now()->subMonths(2)])->save();
    $subscription = $subscription->fresh(['entitlements']);

    // Spent two months ago, so it belongs to a window that has passed.
    Entitlement::factory()->spentFrom($subscription)->format(Format::Live)
        ->grantedAt(now()->subMonths(2))->create();

    expect($subscription?->usedInWindow(Format::Live))->toBe(0)
        ->and($subscription?->remainingFor(Format::Live))->toBe(2);
});

/** A month runs from the member's own start date, not the 1st (agreed 2026-09-13). */
it('resets a monthly allowance on the joining date', function (): void {
    $subscription = member([Format::Live->value => 2], AllowanceWindow::Month);
    $subscription->forceFill(['term_starts_at' => Carbon\Carbon::parse('2026-01-15')])->save();

    Carbon\Carbon::setTestNow('2026-03-20');

    [$start, $end] = $subscription->fresh(['entitlements'])->windowFor(AllowanceWindow::Month);

    expect($start->toDateString())->toBe('2026-03-15')
        ->and($end->toDateString())->toBe('2026-04-15');

    Carbon\Carbon::setTestNow();
});

/** A term allowance does not come back until the term does. */
it('counts a term allowance across the whole membership', function (): void {
    $subscription = member([Format::Live->value => 3], AllowanceWindow::Term);
    $subscription->forceFill(['term_starts_at' => now()->subMonths(6)])->save();
    $subscription = $subscription->fresh(['entitlements']);

    Entitlement::factory()->spentFrom($subscription)->format(Format::Live)
        ->grantedAt(now()->subMonths(5))->create();

    expect($subscription?->usedInWindow(Format::Live))->toBe(1)
        ->and($subscription?->remainingFor(Format::Live))->toBe(2);
});

// Adjusting one format --------------------------------------------------------

it('adjusts one format and leaves the others alone', function (): void {
    $subscription = member([Format::Live->value => 3, Format::Recorded->value => 3]);

    app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, 4, 'Goodwill');

    $subscription = $subscription->fresh(['entitlements']);

    expect($subscription?->allowanceFor(Format::Live)?->quantity)->toBe(4)
        ->and($subscription?->allowanceFor(Format::Recorded)?->quantity)->toBe(3);
});

it('can take an allowance away as well as add one', function (): void {
    $subscription = member([Format::Live->value => 3]);

    app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, 1, 'Sold on the wrong tier');

    expect($subscription->fresh(['entitlements'])?->remainingFor(Format::Live))->toBe(1);
});

it('can make one format unlimited', function (): void {
    $subscription = member([Format::Live->value => 3]);

    app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, null, 'Comped for a year');

    expect($subscription->fresh(['entitlements'])?->allowanceFor(Format::Live)?->isUnlimited())->toBeTrue();
});

/** A format they were never sold can be added, taking its window from the plan. */
it('adds a format the membership did not include', function (): void {
    $subscription = member([Format::Live->value => 3]);

    app(AdjustSubscriptionAllowance::class)($subscription, Format::Download, 2, 'Agreed with the client');

    expect($subscription->fresh(['entitlements'])?->allowanceFor(Format::Download)?->quantity)->toBe(2);
});

it('insists on a reason for an adjustment', function (): void {
    $subscription = member([Format::Live->value => 3]);

    expect(fn () => app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, 4, ''))
        ->toThrow(RuntimeException::class, 'Give a reason');
});

it('refuses a negative allowance', function (): void {
    $subscription = member([Format::Live->value => 3]);

    expect(fn () => app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, -1, 'Oops'))
        ->toThrow(RuntimeException::class, 'cannot be negative');
});

/**
 * Adjusting below what is already spent leaves nothing remaining rather than a
 * negative — an over-consumed allowance is a thing to notice, not a debt to
 * carry.
 */
it('never reports a negative remainder', function (): void {
    $subscription = member([Format::Live->value => 3]);

    redeem($subscription);
    redeem($subscription->fresh(['entitlements']));

    app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, 1, 'Corrected the tier');

    expect($subscription->fresh(['entitlements'])?->remainingFor(Format::Live))->toBe(0);
});

/** The snapshot is what changes, so the plan is untouched for everyone else. */
it('leaves the plan alone when one member is adjusted', function (): void {
    $subscription = member([Format::Live->value => 3]);

    app(AdjustSubscriptionAllowance::class)($subscription, Format::Live, 9, 'Goodwill');

    expect($subscription->plan?->entitlements->firstWhere('format', Format::Live)?->quantity)->toBe(3);
});

// Through the admin -----------------------------------------------------------

/** The allowances list, mounted on a subscription. */
function allowances(Subscription $subscription): Livewire\Features\SupportTesting\Testable
{
    return Livewire\Livewire::test(AllowancesManager::class, [
        'ownerRecord' => $subscription,
        'pageClass' => ViewSubscription::class,
    ]);
}

/** The sessions-used list, mounted on a subscription. */
function redemptions(Subscription $subscription): Livewire\Features\SupportTesting\Testable
{
    return Livewire\Livewire::test(RedemptionsManager::class, [
        'ownerRecord' => $subscription,
        'pageClass' => ViewSubscription::class,
    ]);
}

it('adjusts one format from the allowances list', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::SuperAdmin));

    $subscription = member([Format::Live->value => 3, Format::Recorded->value => 3]);
    $live = $subscription->allowanceFor(Format::Live);

    allowances($subscription)
        ->callTableAction('adjust', $live, ['unlimited' => false, 'quantity' => 4, 'reason' => 'Goodwill']);

    $subscription = $subscription->fresh(['entitlements']);

    expect($subscription?->allowanceFor(Format::Live)?->quantity)->toBe(4)
        ->and($subscription?->allowanceFor(Format::Recorded)?->quantity)->toBe(3);
});

/**
 * The whole support case, through the screens a support person actually uses.
 */
it('gives a session back from the sessions-used list', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::Support));

    $subscription = member([Format::Live->value => 3]);
    $spent = redeem($subscription);

    redemptions($subscription->fresh(['entitlements']))
        ->callTableAction('giveBack', $spent, ['reason' => 'Could not attend; sent the recording']);

    expect($subscription->fresh(['entitlements'])?->remainingFor(Format::Live))->toBe(3)
        ->and($spent->fresh()?->isRevoked())->toBeTrue();
});

it('comps a replacement without spending an allowance', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::Support));

    $subscription = member([Format::Live->value => 3, Format::Recorded->value => 2]);
    $webinar = Webinar::factory()->create();

    // No record: "Grant access" sits in the table header, not on a row.
    redemptions($subscription)->callTableAction('grant', null, [
        'webinar_id' => $webinar->getKey(),
        'format' => Format::Recorded->value,
        'reason' => 'Replacement for the session they missed',
    ]);

    expect(Entitlement::grants($subscription->member, $webinar, Format::Recorded))->toBeTrue()
        // The comp is staff-given, so the quota is untouched.
        ->and($subscription->fresh(['entitlements'])?->remainingFor(Format::Recorded))->toBe(2);
});

/**
 * §8 splits these deliberately: Support resolves tickets, and changing what
 * somebody is entitled to is not a ticket resolution.
 */
it('lets Support give a session back but not change the allowance', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::Support));

    $subscription = member([Format::Live->value => 3]);
    $spent = redeem($subscription);

    redemptions($subscription)->assertTableActionVisible('giveBack', $spent);

    allowances($subscription->fresh(['entitlements']))
        ->assertTableActionHidden('adjust', $subscription->allowanceFor(Format::Live));
});

it('keeps the Content Editor away from both', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::ContentEditor));

    $subscription = member([Format::Live->value => 3]);
    $spent = redeem($subscription);

    redemptions($subscription)->assertTableActionHidden('giveBack', $spent);

    allowances($subscription->fresh(['entitlements']))
        ->assertTableActionHidden('adjust', $subscription->allowanceFor(Format::Live));
});

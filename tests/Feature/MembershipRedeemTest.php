<?php

declare(strict_types=1);

use App\Actions\Memberships\RedeemMembershipAllowance;
use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\MembershipPlan;
use App\Models\Offering;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Webinar;

/*
 * Redeem-to-unlock — §5.2 / P5 slice 2. A member spends an allowance on a
 * webinar from its page; the write is the tested RedeemMembershipAllowance, and
 * the page then shows the format as held.
 */

/**
 * A member whose plan grants `$format`, and a public webinar sold in it.
 *
 * @return array{0: User, 1: Webinar, 2: Subscription}
 */
function memberAndWebinar(Format $format = Format::Recorded, int $quantity = 2): array
{
    $user = User::factory()->create();
    $plan = MembershipPlan::factory()->monthly()->granting([$format->value => $quantity])->create();
    $subscription = Subscription::factory()->for($user)->forPlan($plan)->create(['stripe_price' => $plan->stripe_price_id]);
    app(SnapshotPlanEntitlements::class)($subscription, $plan);

    $webinar = Webinar::factory()->completed()->create();
    Offering::factory()->withFormats($format)->create([
        'offerable_type' => Webinar::class,
        'offerable_id' => $webinar->getKey(),
    ]);

    return [$user, $webinar->fresh(), $subscription->refresh()];
}

// Redeeming --------------------------------------------------------------------

it('grants a ledger entitlement when a member watches with their membership', function (): void {
    [$user, $webinar] = memberAndWebinar(Format::Recorded);

    $this->actingAs($user)
        ->from(route('webinars.show', $webinar))
        ->post(route('membership.watch', $webinar->slug), ['format' => 'recorded'])
        ->assertRedirect(route('webinars.show', $webinar))
        ->assertSessionHas('membershipStatus');

    expect(Entitlement::grants($user, $webinar, Format::Recorded))->toBeTrue()
        ->and(Entitlement::query()->count())->toBe(1);
});

it('refuses a format the webinar is not sold in', function (): void {
    [$user, $webinar] = memberAndWebinar(Format::Recorded);
    // The plan grants transcripts too, but this webinar is only sold recorded.
    $user->membership()?->entitlements()->create(['format' => Format::Transcript, 'quantity' => 2, 'window' => App\Enums\AllowanceWindow::Month]);

    $this->actingAs($user)
        ->from(route('webinars.show', $webinar))
        ->post(route('membership.watch', $webinar->slug), ['format' => 'transcript'])
        ->assertRedirect(route('webinars.show', $webinar))
        ->assertSessionHas('membershipError');

    expect(Entitlement::query()->count())->toBe(0);
});

it('surfaces a quota-exhausted redemption as an error', function (): void {
    [$user, $webinar, $subscription] = memberAndWebinar(Format::Recorded, quantity: 1);

    // Spend the single allowance on another webinar first.
    $other = Webinar::factory()->completed()->create();
    app(RedeemMembershipAllowance::class)($subscription, $other, Format::Recorded);

    $this->actingAs($user)
        ->from(route('webinars.show', $webinar))
        ->post(route('membership.watch', $webinar->slug), ['format' => 'recorded'])
        ->assertSessionHas('membershipError');

    expect(Entitlement::grants($user, $webinar, Format::Recorded))->toBeFalse();
});

it('sends a guest to log in', function (): void {
    [, $webinar] = memberAndWebinar();

    $this->post(route('membership.watch', $webinar->slug), ['format' => 'recorded'])
        ->assertRedirect(route('login'));
});

// The webinar-page panel -------------------------------------------------------

it('offers the member a redeem button on a webinar their plan covers', function (): void {
    [$user, $webinar] = memberAndWebinar(Format::Recorded);

    $this->actingAs($user)
        ->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        ->assertSee('Included with your membership')
        ->assertSee('Add to my account');
});

it('shows an open link once the member has redeemed', function (): void {
    [$user, $webinar, $subscription] = memberAndWebinar(Format::Recorded);
    app(RedeemMembershipAllowance::class)($subscription, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        ->assertSee(route('learning.access', [$webinar->slug, 'recorded']), escape: false)
        ->assertDontSee('Add to my account');
});

it('shows no membership section to a guest', function (): void {
    [, $webinar] = memberAndWebinar(Format::Recorded);

    $this->get(route('webinars.show', $webinar))
        ->assertSuccessful()
        ->assertDontSee('Included with your membership');
});

// The account membership page --------------------------------------------------

it('shows the member their plan and allowance in the account area', function (): void {
    [$user] = memberAndWebinar(Format::Recorded);
    $user->membership()?->update(['membership_plan_id' => MembershipPlan::query()->value('id')]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-membership'))
        ->assertSuccessful()
        ->assertSee('Manage membership');
});

it('prompts a non-member to see the plans', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('filament.account.pages.my-membership'))
        ->assertSuccessful()
        ->assertSee('See membership plans');
});

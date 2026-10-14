<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Models\User;

/*
 * The public pricing page and the subscribe entry point — §6 / P4 slice 1.
 * Taking payment is the next slice; this is about what the pages show and who
 * may reach them.
 */

/** A plan that can actually be sold: active, priced, with an allowance. */
function sellablePlan(array $attributes = []): MembershipPlan
{
    return MembershipPlan::factory()
        ->granting([Format::Live->value => 4])
        ->create($attributes);
}

it('lists active plans with their allowances on the pricing page', function (): void {
    $plan = sellablePlan(['name' => 'Professional', 'is_featured' => true]);

    $this->get(route('membership.index'))
        ->assertSuccessful()
        ->assertSee('Professional')
        ->assertSee('MOST POPULAR')
        // The subscribe CTA points at the real route, not the old JS placeholder.
        ->assertSee(route('membership.subscribe', $plan), escape: false);
});

it('does not offer a subscribe link for a recurring plan with no Stripe price', function (): void {
    MembershipPlan::factory()->monthly()->granting([Format::Live->value => 4])
        ->create(['name' => 'Legacy Tier', 'stripe_price_id' => null]);

    $this->get(route('membership.index'))
        ->assertSuccessful()
        ->assertSee('Legacy Tier')
        ->assertSee('Contact Us');
});

it('sends a guest to log in before subscribing', function (): void {
    $plan = sellablePlan();

    $this->get(route('membership.subscribe', $plan))
        ->assertRedirect(route('login'));
});

it('shows the subscribe page to a signed-in member', function (): void {
    $plan = sellablePlan(['name' => 'Professional']);

    $this->actingAs(User::factory()->create())
        ->get(route('membership.subscribe', $plan))
        ->assertSuccessful()
        ->assertSee('Professional')
        ->assertSee('Due today');
});

it('404s the subscribe page for an unsellable plan', function (): void {
    // No allowances, so problems() is non-empty and isSellable() is false.
    $plan = MembershipPlan::factory()->create(['name' => 'Draft Tier']);

    $this->actingAs(User::factory()->create())
        ->get(route('membership.subscribe', $plan))
        ->assertNotFound();
});

it('404s the subscribe page for an inactive plan', function (): void {
    $plan = MembershipPlan::factory()->inactive()->granting([Format::Live->value => 4])->create();

    $this->actingAs(User::factory()->create())
        ->get(route('membership.subscribe', $plan))
        ->assertNotFound();
});

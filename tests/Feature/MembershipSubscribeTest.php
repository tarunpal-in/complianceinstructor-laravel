<?php

declare(strict_types=1);

use App\Actions\Memberships\StartSubscription;
use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;

/*
 * The inline subscribe flow — §8 / P4 slice 2. The Stripe calls (SetupIntent,
 * Cashier's create) need sandbox credentials and are verified there; these cover
 * everything around them: the gating, the config-aware UI, and the guard that
 * runs before any Stripe call.
 */

function subscribablePlan(array $attributes = []): MembershipPlan
{
    return MembershipPlan::factory()
        ->monthly()
        ->granting([Format::Live->value => 4])
        ->create($attributes);
}

// The subscribe page -----------------------------------------------------------

it('renders the card form when Stripe is configured', function (): void {
    config(['services.stripe.key' => 'pk_test_x']);
    $plan = subscribablePlan();

    $this->actingAs(User::factory()->create())
        ->get(route('membership.subscribe', $plan))
        ->assertSuccessful()
        ->assertSee('mb-payment-element', escape: false)
        ->assertSee('js.stripe.com', escape: false);
});

it('shows a not-configured note when Stripe has no key', function (): void {
    config(['services.stripe.key' => null, 'cashier.key' => null]);
    $plan = subscribablePlan();

    $this->actingAs(User::factory()->create())
        ->get(route('membership.subscribe', $plan))
        ->assertSuccessful()
        ->assertSee('not configured yet')
        ->assertDontSee('mb-payment-element', escape: false);
});

// The AJAX endpoints -----------------------------------------------------------

it('requires a signed-in user to start a setup intent', function (): void {
    $plan = subscribablePlan();

    $this->post(route('membership.subscribe.intent', $plan))
        ->assertRedirect(route('login'));
});

it('refuses a setup intent when Stripe is not configured', function (): void {
    config(['services.stripe.secret' => null, 'cashier.secret' => null]);
    $plan = subscribablePlan();

    $this->actingAs(User::factory()->create())
        ->postJson(route('membership.subscribe.intent', $plan))
        ->assertStatus(422)
        ->assertJson(['error' => 'Card payments are not configured yet.']);
});

it('rejects a confirm with no payment method', function (): void {
    $plan = subscribablePlan();

    $this->actingAs(User::factory()->create())
        ->postJson(route('membership.subscribe.confirm', $plan), [])
        ->assertStatus(422)
        ->assertJson(['error' => 'No payment method was provided.']);
});

it('404s the subscribe endpoints for an unsellable plan', function (): void {
    $plan = MembershipPlan::factory()->create(); // no allowances -> not sellable

    $this->actingAs(User::factory()->create())
        ->postJson(route('membership.subscribe.intent', $plan))
        ->assertNotFound();
});

// The guard (runs before any Stripe call) --------------------------------------

it('refuses to start a second active membership', function (): void {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(); // active 'default' subscription
    $plan = subscribablePlan();

    expect(fn () => app(StartSubscription::class)($user, $plan, 'pm_card_visa'))
        ->toThrow(RuntimeException::class, 'already have an active membership');
});

it('refuses to subscribe to a plan with no Stripe price', function (): void {
    $user = User::factory()->create();
    $plan = subscribablePlan(['stripe_price_id' => null]);

    expect(fn () => app(StartSubscription::class)($user, $plan, 'pm_card_visa'))
        ->toThrow(RuntimeException::class, 'cannot be subscribed to yet');
});

it('surfaces the guard failure through the confirm endpoint', function (): void {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create();
    $plan = subscribablePlan();

    $this->actingAs($user)
        ->postJson(route('membership.subscribe.confirm', $plan), ['payment_method' => 'pm_card_visa'])
        ->assertStatus(422)
        ->assertJson(['error' => 'You already have an active membership. Manage it from your account.']);
});

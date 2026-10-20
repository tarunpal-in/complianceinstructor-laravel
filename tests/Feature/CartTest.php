<?php

declare(strict_types=1);

use App\Cart\CartManager;
use App\Livewire\CartPage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offering;
use App\Models\Page;
use App\Models\User;
use Livewire\Livewire;

/*
 * The cart — CLAUDE.md §5.4 / §6 (P3). The load-bearing rule under test is §14
 * #1: the cart reads the price from the offering, never from the request, and
 * every total is the server's.
 */

// Adding -----------------------------------------------------------------------

it('adds an offering, pricing from the offering and ignoring any request price', function (): void {
    $offering = Offering::factory()->live()->create(['price_cents' => 17900]);

    // The hidden price/total a predecessor-style attacker would send is ignored.
    $this->post('/cart/add', [
        'quantities' => [$offering->id => 2],
        'unit_price_cents' => 1,
        'total' => 1,
    ])->assertRedirect(route('cart.index'));

    $cart = Cart::query()->sole();

    expect($cart->items)->toHaveCount(1);
    expect($cart->items->first()->quantity)->toBe(2)
        ->and($cart->items->first()->unit_price_cents)->toBe(17900)
        ->and($cart->subtotalCents())->toBe(35800);
});

it('ignores offerings that are not on sale or do not exist', function (): void {
    $inactive = Offering::factory()->inactive()->create();

    $this->post('/cart/add', ['quantities' => [$inactive->id => 1, 999999 => 1]]);

    expect(Cart::query()->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(0);
});

it('validates the quantities payload', function (): void {
    $this->post('/cart/add', ['quantities' => 'nope'])->assertSessionHasErrors('quantities');
});

it('raises the quantity when the same offering is added again, clamped to the max', function (): void {
    $this->actingAs(User::factory()->create());
    $offering = Offering::factory()->live()->create();
    $manager = app(CartManager::class);

    $manager->add($offering, 15);
    $manager->add($offering, 15);

    expect($manager->current()->items->first()->quantity)->toBe(CartManager::MAX_QUANTITY);
});

// Mutating via the Livewire page --------------------------------------------

it('updates a quantity and removes a line from the cart page', function (): void {
    $user = User::factory()->create();
    $offering = Offering::factory()->live()->create();
    $this->actingAs($user);
    app(CartManager::class)->add($offering, 1);
    $item = Cart::query()->sole()->items->first();

    Livewire::actingAs($user)->test(CartPage::class)
        ->call('updateQuantity', $item->id, 3)
        ->assertOk();
    expect($item->fresh()->quantity)->toBe(3);

    Livewire::actingAs($user)->test(CartPage::class)->call('removeItem', $item->id);
    expect(CartItem::query()->find($item->id))->toBeNull();
});

it('setting a quantity to zero removes the line', function (): void {
    $user = User::factory()->create();
    $offering = Offering::factory()->live()->create();
    $this->actingAs($user);
    app(CartManager::class)->add($offering, 2);
    $item = Cart::query()->sole()->items->first();

    Livewire::actingAs($user)->test(CartPage::class)->call('updateQuantity', $item->id, 0);

    expect(CartItem::query()->find($item->id))->toBeNull();
});

it('cannot touch a line in someone else\'s cart', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $offering = Offering::factory()->live()->create();

    $this->actingAs($owner);
    app(CartManager::class)->add($offering, 1);
    $item = Cart::query()->where('user_id', $owner->id)->sole()->items->first();

    // §9 — IDOR: the intruder's component only ever touches its own cart.
    Livewire::actingAs($intruder)->test(CartPage::class)->call('removeItem', $item->id);

    expect(CartItem::query()->find($item->id))->not->toBeNull();
});

// Resolution + merge ---------------------------------------------------------

it('merges a guest cart into the user cart on sign-in', function (): void {
    $offering = Offering::factory()->live()->create();

    $this->post('/cart/add', ['quantities' => [$offering->id => 2]]);
    expect(Cart::query()->whereNull('user_id')->count())->toBe(1);

    $user = User::factory()->create();
    $this->actingAs($user);
    $cart = app(CartManager::class)->current();

    expect($cart->user_id)->toBe($user->id)
        ->and($cart->items->first()->quantity)->toBe(2)
        ->and(Cart::query()->whereNull('user_id')->count())->toBe(0);
});

// Page -----------------------------------------------------------------------

it('renders the cart page with an empty state', function (): void {
    $this->get('/cart')
        ->assertSuccessful()
        ->assertSee('Your cart')
        ->assertSee('Your cart is empty');
});

it('shows a line on the cart page once something is added', function (): void {
    $user = User::factory()->create();
    $offering = Offering::factory()->live()->create();
    $webinar = $offering->offerable;
    $this->actingAs($user);
    app(CartManager::class)->add($offering, 1);

    $this->get('/cart')->assertSuccessful()->assertSee($webinar->title);
});

it('reserves the cart and checkout slugs', function (): void {
    expect(Page::RESERVED_SLUGS)->toContain('cart')->toContain('checkout');
});

// Header cart count ----------------------------------------------------------

it('shows the total unit count on the header cart badge', function (): void {
    $user = User::factory()->create();
    $offering = Offering::factory()->live()->create();
    $this->actingAs($user);
    app(CartManager::class)->add($offering, 3);

    Livewire::test(App\Livewire\CartCount::class)
        ->assertSee('cart-badge')
        ->assertSee('3');
});

it('shows no badge when the cart is empty', function (): void {
    Livewire::test(App\Livewire\CartCount::class)
        ->assertDontSee('cart-badge');
});

// Account-gated checkout -----------------------------------------------------

it('shows a guest the sign-in form, not a checkout button', function (): void {
    $offering = Offering::factory()->live()->create();
    $this->post('/cart/add', ['quantities' => [$offering->id => 1]]);

    $this->get('/cart')
        ->assertSuccessful()
        ->assertSee('Sign in to check out')
        ->assertSee('Create account')
        ->assertDontSee('Proceed to checkout');
});

it('shows a signed-in customer the checkout button, not the sign-in form', function (): void {
    $user = User::factory()->create();
    $offering = Offering::factory()->live()->create();
    $this->actingAs($user);
    app(CartManager::class)->add($offering, 1);

    $this->get('/cart')
        ->assertSuccessful()
        ->assertSee('Proceed to checkout')
        ->assertDontSee('Sign in to check out');
});

it('redirects a guest away from checkout back to the cart to sign in', function (): void {
    $offering = Offering::factory()->live()->create();
    $this->post('/cart/add', ['quantities' => [$offering->id => 1]]);

    $this->get('/checkout')
        ->assertRedirect(route('cart.index'))
        ->assertSessionHas('cart_error');
});

it('sends a guest to checkout after signing in from the cart', function (): void {
    $offering = Offering::factory()->live()->create();
    $this->post('/cart/add', ['quantities' => [$offering->id => 1]]);

    $user = User::factory()->create(['password' => 'secret-password']);

    Livewire::test(App\Livewire\Auth\CartAuth::class)
        ->set('email', $user->email)
        ->set('password', 'secret-password')
        ->call('login')
        ->assertRedirect(route('checkout.index'));

    // The guest cart followed them into their account (CartManager merge).
    expect(app(CartManager::class)->current()->items)->toHaveCount(1);
});

it('sends a new registrant to checkout after signing up from the cart', function (): void {
    $offering = Offering::factory()->live()->create();
    $this->post('/cart/add', ['quantities' => [$offering->id => 1]]);

    Livewire::test(App\Livewire\Auth\CartAuth::class)
        ->set('mode', 'register')
        ->set('name', 'New Buyer')
        ->set('email', 'new.buyer@example.com')
        ->set('password', 'Str0ng-Passw0rd!')
        ->set('password_confirmation', 'Str0ng-Passw0rd!')
        ->call('register')
        ->assertRedirect(route('checkout.index'));

    expect(app(CartManager::class)->current()->items)->toHaveCount(1);
});

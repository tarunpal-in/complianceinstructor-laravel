<?php

declare(strict_types=1);

use App\Cart\CartManager;
use App\Enums\OrderStatus;
use App\Livewire\CheckoutPage;
use App\Models\Coupon;
use App\Models\Offering;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

/*
 * The checkout page (P3). It collects details and places the order through the
 * tested PlaceOrder action; the totals it shows are the server's on every
 * re-render, and payment is deferred to the next slice (the order is Pending).
 */

function seedCart(User $user, int $quantity = 1, ?Offering $offering = null): Offering
{
    $offering ??= Offering::factory()->live()->create(['price_cents' => 20000]);
    test()->actingAs($user);
    app(CartManager::class)->add($offering, $quantity);

    return $offering;
}

function unscopedCoupon(string $code, int $amountOff): Coupon
{
    return Coupon::factory()->create([
        'code' => $code,
        'percent_off' => null,
        'amount_off_cents' => $amountOff,
        'currency' => 'USD',
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'max_redemptions' => null,
        'max_per_user' => null,
        'minimum_spend_cents' => null,
        'scopeable_type' => null,
        'scopeable_id' => null,
        'scope_format' => null,
    ]);
}

// Routing --------------------------------------------------------------------

it('redirects checkout to the cart when there is nothing to buy', function (): void {
    $this->actingAs(User::factory()->create());
    $this->get('/checkout')->assertRedirect(route('cart.index'));
});

it('renders checkout once the cart has items', function (): void {
    $user = User::factory()->create();
    seedCart($user);

    $this->actingAs($user)->get('/checkout')->assertSuccessful()->assertSee('Checkout');
});

// The component --------------------------------------------------------------

it('seeds one attendee field per seat', function (): void {
    $user = User::factory()->create();
    $offering = Offering::factory()->live()->create(['seats_per_unit' => 2]);
    seedCart($user, 2, $offering); // 2 units × 2 seats

    $component = Livewire::actingAs($user)->test(CheckoutPage::class);

    expect($component->get('attendees'))->toHaveCount(4);
});

it('previews a valid coupon discount and clears it on removal', function (): void {
    $user = User::factory()->create();
    seedCart($user, 1, Offering::factory()->live()->create(['price_cents' => 20000]));
    unscopedCoupon('TENOFF', 5000);

    $component = Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('couponCode', 'TENOFF')
        ->call('applyCoupon');

    expect($component->get('appliedCoupon'))->toBe('TENOFF')
        ->and($component->get('couponDiscount'))->toBe(5000);

    $component->call('removeCoupon');
    expect($component->get('appliedCoupon'))->toBeNull()
        ->and($component->get('couponDiscount'))->toBe(0);
});

it('rejects an unknown coupon code', function (): void {
    $user = User::factory()->create();
    seedCart($user);

    $component = Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('couponCode', 'NOPE')
        ->call('applyCoupon');

    expect($component->get('couponError'))->not->toBeNull()
        ->and($component->get('appliedCoupon'))->toBeNull();
});

it('validates the billing and attendee fields', function (): void {
    $user = User::factory()->create();
    seedCart($user);

    Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('name', '')
        ->set('email', 'not-an-email')
        ->set('attendees.0.name', '')
        ->set('attendees.0.email', '')
        ->call('place')
        ->assertHasErrors([
            'name', 'email', 'jobTitle', 'addressLine1', 'city', 'postalCode',
            'attendees.0.name', 'attendees.0.email',
        ]);

    expect(Order::query()->count())->toBe(0);
});

it('defaults the billing country to the United States', function (): void {
    $user = User::factory()->create();
    seedCart($user);

    expect(Livewire::actingAs($user)->test(CheckoutPage::class)->get('country'))
        ->toBe('United States');
});

it('rejects a country that is not on the list', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.test']);
    seedCart($user, 1, Offering::factory()->live()->create(['price_cents' => 20000]));

    Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('name', 'Buyer')->set('email', 'buyer@example.test')
        ->set('jobTitle', 'HR Manager')->set('addressLine1', '100 Market St')
        ->set('city', 'Austin')->set('postalCode', '78701')
        ->set('country', 'Atlantis')
        ->set('attendees.0.name', 'Buyer')->set('attendees.0.email', 'buyer@example.test')
        ->call('place')
        ->assertHasErrors(['country']);

    expect(Order::query()->count())->toBe(0);
});

it('places a pending order and redirects to the pay page', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.test']);
    seedCart($user, 1, Offering::factory()->live()->create(['price_cents' => 20000]));

    Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('name', 'Buyer')
        ->set('email', 'buyer@example.test')
        ->set('jobTitle', 'HR Manager')
        ->set('addressLine1', '100 Market St')
        ->set('city', 'Austin')
        ->set('postalCode', '78701')
        ->set('country', 'United States')
        ->set('attendees.0.name', 'Buyer')
        ->set('attendees.0.email', 'buyer@example.test')
        ->call('place')
        ->assertRedirect(route('checkout.pay', Order::query()->sole()));

    $order = Order::query()->sole();
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($user->id)
        ->and($order->total_cents)->toBe(20000)
        // The billing snapshot froze the new fields (§5.4).
        ->and($order->billing_snapshot['job_title'])->toBe('HR Manager')
        ->and($order->billing_snapshot['address_line1'])->toBe('100 Market St')
        ->and($order->billing_snapshot['country'])->toBe('United States');
});

it('applies a coupon when placing', function (): void {
    $user = User::factory()->create(['email' => 'buyer@example.test']);
    seedCart($user, 1, Offering::factory()->live()->create(['price_cents' => 20000]));
    unscopedCoupon('TENOFF', 5000);

    Livewire::actingAs($user)->test(CheckoutPage::class)
        ->set('name', 'Buyer')->set('email', 'buyer@example.test')
        ->set('jobTitle', 'HR Manager')->set('addressLine1', '100 Market St')
        ->set('city', 'Austin')->set('postalCode', '78701')->set('country', 'United States')
        ->set('attendees.0.name', 'Buyer')->set('attendees.0.email', 'buyer@example.test')
        ->set('couponCode', 'TENOFF')->call('applyCoupon')
        ->call('place');

    expect(Order::query()->sole()->total_cents)->toBe(15000);
});

// Received page --------------------------------------------------------------

it('shows the received page to the order owner', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'status' => OrderStatus::Pending]);

    $this->actingAs($user)->get(route('checkout.complete', $order))
        ->assertSuccessful()
        ->assertSee($order->number);
});

it('404s the received page for someone who is not the owner', function (): void {
    $order = Order::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('checkout.complete', $order))
        ->assertNotFound();
});

<?php

declare(strict_types=1);

use App\Cart\CartManager;
use App\Enums\Format;
use App\Livewire\MiniCart;
use App\Models\Webinar;
use Livewire\Livewire;

/*
 * The header mini-cart: a universal, read-only cart summary opened from the nav
 * cart icon on every page — distinct from CartDrawer, the webinar-page order-bump.
 * It must open on its own event, show the real cart and subtotal, remove a line,
 * and show an empty state so the icon always opens something.
 *
 * fmtOffering() is a shared helper in tests/Pest.php.
 */

it('opens on mini-cart:open, its own event — not the order-bump cart:open', function (): void {
    Livewire::test(MiniCart::class)
        ->assertSet('open', false)
        ->dispatch('mini-cart:open')
        ->assertSet('open', true);
});

it('shows an empty state when the cart has nothing', function (): void {
    Livewire::test(MiniCart::class)
        ->call('openDrawer')
        ->assertSee('Your cart is empty');
});

it('shows the cart lines, subtotal and a checkout action', function (): void {
    $webinar = Webinar::factory()->create(['title' => 'FMLA Essentials']);
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    app(CartManager::class)->add($live, 1);

    Livewire::test(MiniCart::class)
        ->call('openDrawer')
        ->assertSee('FMLA Essentials')
        ->assertSee('Sub-total')
        ->assertSee('Checkout');
});

it('shows the webinar feature image slot and a quantity stepper', function (): void {
    $webinar = Webinar::factory()->create(['title' => 'FMLA Essentials']);
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    app(CartManager::class)->add($live, 1);

    Livewire::test(MiniCart::class)
        ->call('openDrawer')
        ->assertSee('FMLA Essentials')
        ->assertSee('mc-item-thumb', false)
        ->assertSee('mc-qty', false);
});

it('steps the quantity up, and removing it at zero clears the line', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $item = app(CartManager::class)->add($live, 1);

    $component = Livewire::test(MiniCart::class)->call('openDrawer');

    $component->call('updateQuantity', $item->id, 2)->assertDispatched('cart:changed');
    expect(app(CartManager::class)->peek()?->items->firstWhere('id', $item->id)?->quantity)->toBe(2);

    // setQuantity deletes at zero, so the minus button at one clears the line.
    $component->call('updateQuantity', $item->id, 0);
    expect(app(CartManager::class)->peek()?->isEmpty() ?? true)->toBeTrue();
});

it('removes a line and tells the rest of the page the cart changed', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $item = app(CartManager::class)->add($live, 1);

    Livewire::test(MiniCart::class)
        ->call('openDrawer')
        ->call('removeItem', $item->id)
        ->assertDispatched('cart:changed');

    expect(app(CartManager::class)->peek()?->isEmpty() ?? true)->toBeTrue();
});

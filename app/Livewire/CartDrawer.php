<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Cart\CartManager;
use App\Cart\CrossSell;
use App\Models\Offering;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The mini-cart drawer — the order-bump popup (§5.3 cross-sell).
 *
 * Opens on the `cart:open` event the purchase panel dispatches after adding.
 * Shows the cart with the add-on rule applied (CartPricing), and a "You might
 * also like" panel of the other formats of the webinars in the cart at their
 * add-on price. Adding one keeps the drawer open so the bump can continue.
 *
 * Money is never trusted here (§14 #1): the only input is which offering to add,
 * and every price shown is re-derived from the offering.
 */
class CartDrawer extends Component
{
    public bool $open = false;

    #[On('cart:open')]
    public function openDrawer(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function addAddon(int $offeringId, CartManager $cart): void
    {
        $offering = Offering::query()->where('is_active', true)->find($offeringId);

        if ($offering !== null) {
            $cart->add($offering, 1);
            $this->dispatch('cart:changed');
        }

        $this->open = true;
    }

    public function removeItem(int $cartItemId, CartManager $cart): void
    {
        // Scoped to the current cart, so a request cannot remove another
        // visitor's line (§14 #10).
        $item = $cart->current()->items()->find($cartItemId);

        if ($item !== null) {
            $cart->remove($item);
            $this->dispatch('cart:changed');
        }
    }

    public function render(): View
    {
        $cart = app(CartManager::class)->peek();
        $cart?->loadMissing(['items.offering.formats', 'items.offering.offerable.speaker.media']);

        return view('livewire.cart-drawer', [
            'cart' => $cart,
            'prices' => $cart !== null ? $cart->effectiveUnitPrices() : [],
            'suggestions' => $cart !== null ? app(CrossSell::class)->forCart($cart) : [],
        ]);
    }
}

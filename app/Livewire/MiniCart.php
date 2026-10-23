<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Cart\CartManager;
use App\Models\Webinar;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The header mini-cart — a plain, read-only cart summary opened from the nav bar
 * cart icon on every page.
 *
 * Distinct from CartDrawer, which is the webinar-page order-bump (cross-sell,
 * opens only after an add via `cart:open`, a wide centred modal). This one
 * carries no suggestions: it is the quick "what's in my cart" glance, a side
 * drawer with links out to the full cart and to checkout. Mounted once in the
 * public layout so the icon can open it anywhere, on a distinct `mini-cart:open`
 * event so the two never collide.
 *
 * Money is never trusted here (§14 #1): the only input is which line to remove,
 * and every price shown is re-derived from the offering through the cart.
 */
class MiniCart extends Component
{
    public bool $open = false;

    #[On('mini-cart:open')]
    public function openDrawer(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    #[On('cart:changed')]
    public function refresh(): void
    {
        // Handling the event re-renders, so the drawer reflects a change made
        // elsewhere (the cart page, the webinar order-bump) while it is open.
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

    public function updateQuantity(int $cartItemId, int $quantity, CartManager $cart): void
    {
        // Owner-scoped like removeItem (§14 #10). setQuantity clears the line at
        // zero, so the minus button at a quantity of one removes it — the same
        // behaviour as the full cart page.
        $item = $cart->current()->items()->find($cartItemId);

        if ($item !== null) {
            $cart->setQuantity($item, $quantity);
            $this->dispatch('cart:changed');
        }
    }

    public function render(): View
    {
        $cart = app(CartManager::class)->peek();

        if ($cart !== null) {
            $cart->loadMissing(['items.offering.formats', 'items.offering.offerable']);

            // Speaker and card image live on a webinar offerable, not a package,
            // so load them only there — eager-loading them on a package would
            // trip Model::shouldBeStrict().
            foreach ($cart->items as $item) {
                $offerable = $item->offering?->offerable;

                if ($offerable instanceof Webinar) {
                    $offerable->loadMissing(['speaker', 'cardMedia']);
                }
            }
        }

        return view('livewire.mini-cart', [
            'cart' => $cart,
            // The add-on rule can discount a line, so show the same effective
            // prices and subtotal the cart page and checkout will charge.
            'prices' => $cart !== null ? $cart->effectiveUnitPrices() : [],
        ]);
    }
}

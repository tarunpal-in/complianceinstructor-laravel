<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Cart\CartManager;
use App\Models\Cart;
use App\Models\CartItem;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The cart page — CLAUDE.md §5.4 / §6 (P3).
 *
 * Reactive without a hand-written JS API: changing a quantity or removing a line
 * re-renders from the server, so the totals the customer sees are always the
 * server's (§14 #1), never computed in the browser. Mutations go through
 * CartManager; every line is scoped to the current cart before it is touched, so
 * a forged id cannot reach someone else's cart (§9 — IDOR).
 */
class CartPage extends Component
{
    public function updateQuantity(int $itemId, int $quantity): void
    {
        $this->withOwnedItem($itemId, fn (CartItem $item) => app(CartManager::class)->setQuantity($item, $quantity));
        $this->dispatch('cart:changed');
    }

    public function removeItem(int $itemId): void
    {
        $this->withOwnedItem($itemId, fn (CartItem $item) => app(CartManager::class)->remove($item));
        $this->dispatch('cart:changed');
    }

    /**
     * Run a mutation against a line only if it belongs to the current cart.
     *
     * @param  Closure(CartItem): void  $callback
     */
    private function withOwnedItem(int $itemId, Closure $callback): void
    {
        $item = app(CartManager::class)->current()->items->firstWhere('id', $itemId);

        if ($item !== null) {
            $callback($item);
        }
    }

    public function render(): View
    {
        $cart = app(CartManager::class)->current();

        return view('livewire.cart-page', ['cart' => $cart]);
    }

    /** Exposed for the view/tests: the current cart, freshly resolved. */
    public function cart(): Cart
    {
        return app(CartManager::class)->current();
    }
}

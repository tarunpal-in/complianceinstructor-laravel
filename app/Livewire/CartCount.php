<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Cart\CartManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The cart badge in the header.
 *
 * Server-rendered on every page (so it is right on first paint), and refreshed
 * live when the cart changes elsewhere on the page — adding from the purchase
 * panel (which also opens the drawer) and any add/remove in the drawer or on the
 * cart page all dispatch an event this listens for. It only reads the cart
 * (peek, never create), so a visitor who has not touched the cart gets no row.
 */
class CartCount extends Component
{
    #[On('cart:open')]
    #[On('cart:changed')]
    public function refresh(): void
    {
        // Handling the event is enough to re-render; render() re-reads the count.
    }

    public function render(): View
    {
        return view('livewire.cart-count', [
            'count' => app(CartManager::class)->peek()?->itemCount() ?? 0,
        ]);
    }
}

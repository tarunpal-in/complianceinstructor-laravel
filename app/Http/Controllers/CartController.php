<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cart\CartManager;
use App\Http\Requests\AddToCartRequest;
use App\Models\Offering;
use App\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The cart — §5.4 / §6 (P3).
 *
 * §4 rule 2 keeps this thin: the page is a Livewire component, and adding hands
 * the work to CartManager, which reads prices from the offering (§14 #1). The
 * controller only validates *what* and *how many*.
 */
class CartController extends Controller
{
    public function index(): View
    {
        return view('cart.index', [
            // A cart is a working page, not an indexed one.
            'seo' => Seo::site('Your cart', robots: 'noindex, follow'),
        ]);
    }

    public function add(AddToCartRequest $request, CartManager $cart): RedirectResponse
    {
        $quantities = $request->selectedQuantities();

        if ($quantities === []) {
            return back()->with('cart_error', 'Choose at least one option to add to your cart.');
        }

        // Only offerings that are actually on sale, found by id — a stale or
        // switched-off id is ignored rather than trusted.
        $offerings = Offering::query()
            ->active()
            ->whereKey(array_keys($quantities))
            ->get();

        $added = 0;

        foreach ($offerings as $offering) {
            $cart->add($offering, $quantities[$offering->getKey()] ?? 1);
            $added++;
        }

        if ($added === 0) {
            return back()->with('cart_error', 'Those options are no longer available.');
        }

        return redirect()->route('cart.index')->with('cart_status', 'Added to your cart.');
    }
}

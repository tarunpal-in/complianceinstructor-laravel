<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cart\CartManager;
use App\Models\Order;
use App\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout — §5.4 / §6 (P3).
 *
 * §4 rule 2 keeps this thin: the checkout page is a Livewire component that
 * collects details and hands placing to the PlaceOrder action; the received page
 * just shows an order the visitor is allowed to see.
 */
class CheckoutController extends Controller
{
    public function index(CartManager $cart): View|RedirectResponse
    {
        // Nothing to check out — back to the cart rather than an empty form.
        if (($current = $cart->peek()) === null || $current->isEmpty()) {
            return redirect()->route('cart.index');
        }

        return view('checkout.index', [
            'seo' => Seo::site('Checkout', robots: 'noindex, follow'),
        ]);
    }

    /**
     * The order-received page.
     *
     * Visible to the order's owner, or to whoever just placed it (its id is held
     * in the session) — a guest has no account to authenticate against, so the
     * session is the access control until a signed receipt link lands with the
     * confirmation email.
     */
    public function received(Order $order): View
    {
        $user = auth()->user();
        $isOwner = $user !== null && $order->user_id === $user->getKey();
        $justPlaced = session('recent_order_id') === $order->getKey();

        if (! $isOwner && ! $justPlaced) {
            throw new NotFoundHttpException;
        }

        $order->load(['items.formats', 'items.attendees', 'payments']);

        return view('checkout.received', [
            'seo' => Seo::site('Order received', robots: 'noindex, nofollow'),
            'order' => $order,
        ]);
    }
}

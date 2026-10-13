<?php

declare(strict_types=1);

namespace App\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offering;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Resolves and mutates the current visitor's cart — CLAUDE.md §5.4.
 *
 * A logged-in visitor has one cart keyed on their user id; a guest has one keyed
 * on a session token. Logging in merges the guest cart into the user's, so items
 * added before signing in are not lost. Cart mutation lives here rather than in
 * an Action because nothing here moves money — the money-moving Actions
 * (PlaceOrder, capture) arrive in the checkout slice and re-derive every total
 * from the offering, never from what the cart stored (§14 #1).
 */
class CartManager
{
    /** A sane per-line ceiling, so a typo in a quantity box cannot place 10,000 seats. */
    public const MAX_QUANTITY = 20;

    private const SESSION_KEY = 'cart_token';

    /** The current cart, creating one if the visitor has none yet. */
    public function current(): Cart
    {
        return $this->resolve(create: true);
    }

    /** The current cart if it already exists, without creating one (for the badge). */
    public function peek(): ?Cart
    {
        return $this->resolve(create: false);
    }

    /**
     * Add an offering to the cart, raising the quantity if it is already there.
     *
     * The price is read from the offering (§14 #1 — never from the request), and
     * stored only as a display snapshot; checkout re-reads it.
     */
    public function add(Offering $offering, int $quantity = 1): CartItem
    {
        $cart = $this->current();
        $existingQuantity = (int) $cart->items()->where('offering_id', $offering->getKey())->value('quantity');

        $quantity = $this->clamp($existingQuantity + max(1, $quantity));

        return $cart->items()->updateOrCreate(
            ['offering_id' => $offering->getKey()],
            ['quantity' => $quantity, 'unit_price_cents' => $offering->priceCents()],
        );
    }

    /** Set a line's quantity, removing it at zero. */
    public function setQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => $this->clamp($quantity)]);
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    private function clamp(int $quantity): int
    {
        return max(1, min(self::MAX_QUANTITY, $quantity));
    }

    private function resolve(bool $create): ?Cart
    {
        $user = Auth::user();

        if ($user !== null) {
            $cart = $create
                ? Cart::firstOrCreate(['user_id' => $user->getKey()], ['currency' => 'USD'])
                : Cart::query()->where('user_id', $user->getKey())->first();

            if ($cart !== null) {
                $this->mergeGuestInto($cart);
            }

            return $cart?->load(['items.offering.formats', 'items.offering.offerable']);
        }

        $token = $this->guestToken($create);

        if ($token === null) {
            return null;
        }

        $cart = $create
            ? Cart::firstOrCreate(['session_token' => $token, 'user_id' => null], ['currency' => 'USD'])
            : Cart::query()->whereNull('user_id')->where('session_token', $token)->first();

        return $cart?->load(['items.offering.formats', 'items.offering.offerable']);
    }

    /** The guest's cart token, minting one only when we are allowed to create. */
    private function guestToken(bool $create): ?string
    {
        $token = session(self::SESSION_KEY);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        if (! $create) {
            return null;
        }

        $token = Str::random(40);
        session([self::SESSION_KEY => $token]);

        return $token;
    }

    /** Fold a signed-out cart into the user's on login, then retire the token. */
    private function mergeGuestInto(Cart $userCart): void
    {
        $token = session(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return;
        }

        $guest = Cart::query()->whereNull('user_id')->where('session_token', $token)->with('items')->first();

        if ($guest === null || $guest->is($userCart)) {
            return;
        }

        foreach ($guest->items as $item) {
            $existingQuantity = (int) $userCart->items()->where('offering_id', $item->offering_id)->value('quantity');

            $userCart->items()->updateOrCreate(
                ['offering_id' => $item->offering_id],
                [
                    'quantity' => $this->clamp($existingQuantity + $item->quantity),
                    'unit_price_cents' => $item->unit_price_cents,
                ],
            );
        }

        $guest->delete();
        session()->forget(self::SESSION_KEY);
    }
}

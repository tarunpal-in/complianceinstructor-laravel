<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Auth\Concerns\AuthenticatesCustomers;
use App\Support\SocialProviders;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The two-tab sign-in / sign-up form embedded on the cart page.
 *
 * Checkout now requires an account (no guest checkout), so a signed-out visitor
 * signs in or registers here without leaving their cart. The guest cart merges
 * into the account on login (CartManager), and a successful auth lands on
 * checkout. The behaviour is shared with the standalone page via
 * AuthenticatesCustomers — only the landing differs.
 */
class CartAuth extends Component
{
    use AuthenticatesCustomers;

    public string $mode = 'login';

    public function render(): View
    {
        return view('livewire.auth.cart-auth', [
            'providers' => SocialProviders::enabled(),
        ]);
    }

    protected function afterAuthRedirect(): mixed
    {
        // Straight on to checkout — the cart they just signed in with is waiting.
        return $this->redirectRoute('checkout.index', navigate: false);
    }
}

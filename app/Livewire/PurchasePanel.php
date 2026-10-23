<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Cart\CartManager;
use App\Models\Entitlement;
use App\Models\Webinar;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The purchase panel on the webinar page (§6 / P3), now Livewire so adding to the
 * cart can open the mini-cart drawer with its cross-sell (the order-bump UX).
 *
 * For a signed-in member it is also their membership panel: a single-format
 * offering the plan covers is shown as "included" (open it, or redeem it through
 * the tested membership.watch route) rather than as something to buy, so a member
 * sees one box — what they already have, then what they can buy — instead of two
 * competing ones (§5.2 / P5).
 *
 * Nothing here is trusted for money (§14 #1): the quantities are the only input,
 * and the cart/checkout re-derive every price from the offering, applying the
 * add-on rule in CartPricing. The total shown is the sum of the selected
 * standalone prices — a display figure the drawer then restates at the real
 * add-on-adjusted subtotal.
 */
class PurchasePanel extends Component
{
    public Webinar $webinar;

    /** @var array<int, int> offering id => quantity */
    public array $quantities = [];

    public int $maxQuantity = 10;

    public function mount(): void
    {
        foreach ($this->offerings() as $offering) {
            $this->quantities[$offering->getKey()] = 0;
        }
    }

    /** @return Collection<int, \App\Models\Offering> */
    public function offerings(): Collection
    {
        // Livewire re-resolves the model fresh each request, so load the relations
        // the view and pricing read rather than relying on an eager load that did
        // not survive hydration (Model::shouldBeStrict() would throw otherwise).
        $this->webinar->loadMissing(['offerings.formats', 'offerings.offerable']);

        return $this->webinar->offerings
            ->where('is_active', true)
            ->sortBy('sort_order')
            ->values();
    }

    public function totalCents(): int
    {
        return $this->offerings()->sum(function ($offering): int {
            $qty = max(0, (int) ($this->quantities[$offering->getKey()] ?? 0));

            return $offering->priceCents() * $qty;
        });
    }

    public function add(CartManager $cart): void
    {
        $added = false;

        foreach ($this->offerings() as $offering) {
            $qty = max(0, min($this->maxQuantity, (int) ($this->quantities[$offering->getKey()] ?? 0)));

            if ($qty > 0) {
                $cart->add($offering, $qty);
                $added = true;
            }
        }

        if (! $added) {
            return;
        }

        foreach (array_keys($this->quantities) as $id) {
            $this->quantities[$id] = 0;
        }

        // Tell the drawer to refresh and open — the order-bump moment.
        $this->dispatch('cart:open');
    }

    /**
     * How the signed-in member's plan touches each single-format offering —
     * keyed by format value, or [] for a guest / non-member. Computed fresh each
     * render so a redemption's "held" state shows after the page reloads.
     *
     * @return array<string, array{state: string, remaining: string}>
     */
    private function membershipStates(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $subscription = $user->membership();

        if ($subscription === null || ! $subscription->state()->grantsAccess()) {
            return [];
        }

        $subscription->loadMissing('entitlements');

        $states = [];

        foreach ($this->offerings() as $offering) {
            $formats = $offering->formatEnums();

            // Only a single-format offering maps to a membership allowance; a
            // bundle (e.g. Live + Recorded) is bought, never redeemed as a unit.
            if ($formats->count() !== 1) {
                continue;
            }

            $format = $formats->first();

            if ($subscription->allowanceFor($format) === null || isset($states[$format->value])) {
                continue;
            }

            $states[$format->value] = [
                'state' => match (true) {
                    Entitlement::grants($user, $this->webinar, $format) => 'held',
                    $subscription->canSpend($format) => 'spendable',
                    default => 'exhausted',
                },
                'remaining' => $subscription->describeRemaining($format),
            ];
        }

        return $states;
    }

    public function render(): View
    {
        $states = $this->membershipStates();

        $included = [];
        $buy = [];

        foreach ($this->offerings() as $offering) {
            $formats = $offering->formatEnums();
            $format = $formats->count() === 1 ? $formats->first() : null;
            $state = $format !== null ? ($states[$format->value] ?? null) : null;

            if ($state !== null) {
                $included[] = [
                    'offering' => $offering,
                    'format' => $format,
                    'state' => $state['state'],
                    'remaining' => $state['remaining'],
                ];
            } else {
                $buy[] = $offering;
            }
        }

        return view('livewire.purchase-panel', [
            'included' => $included,
            'buyOfferings' => collect($buy),
            'hasMembership' => $included !== [],
            'formattedTotal' => Money::format($this->totalCents()),
        ]);
    }
}

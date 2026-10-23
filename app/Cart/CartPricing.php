<?php

declare(strict_types=1);

namespace App\Cart;

use App\Models\CartItem;
use App\Models\Webinar;

/**
 * The add-on cross-sell pricing rule — CLAUDE.md §5.3 / §14 #1.
 *
 * A webinar's offerings are sold both on their own and as cheaper add-ons
 * bought alongside another offering of the same webinar (the `addon_price_cents`
 * the data model already carries). This decides, from the cart as a whole, which
 * lines get which price:
 *
 *   For each webinar, its most-expensive offering is the PRIMARY and keeps its
 *   full price; every other offering of that same webinar — a single format or a
 *   multi-format bundle alike — is charged at its add-on price. Packages are
 *   always their own standalone price.
 *
 * Deterministic and order-independent — the primary is chosen by price, not by
 * what was added first — so the checkout recomputes exactly the cart's totals
 * (§14 #1: money is never trusted from the request, only re-derived here).
 */
class CartPricing
{
    /**
     * The effective unit price in cents for each cart item.
     *
     * @param  iterable<CartItem>  $items  with offering.formats and offering.offerable loaded
     * @return array<int, int> cart item id => unit price cents
     */
    public function unitPrices(iterable $items): array
    {
        $items = is_array($items) ? $items : iterator_to_array($items);

        $primaryIds = $this->primaryItemIds($items);

        $prices = [];

        foreach ($items as $item) {
            $offering = $item->offering;

            if ($offering === null || ! $item->isAvailable()) {
                $prices[$item->getKey()] = 0;

                continue;
            }

            // An add-on only when it is a webinar offering that is not the
            // primary for its webinar — bundles included.
            $asAddon = $this->isWebinarOffering($item) && ! isset($primaryIds[$item->getKey()]);

            $prices[$item->getKey()] = $offering->priceCents($asAddon);
        }

        return $prices;
    }

    /**
     * The whole cart's subtotal with the rule applied.
     *
     * @param  iterable<CartItem>  $items
     */
    public function subtotalCents(iterable $items): int
    {
        $items = is_array($items) ? $items : iterator_to_array($items);
        $unit = $this->unitPrices($items);

        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += ($unit[$item->getKey()] ?? 0) * $item->quantity;
        }

        return $subtotal;
    }

    /**
     * The one primary (full-price) cart item per webinar — the most expensive
     * single-format offering, ties broken by the lowest offering id so the
     * choice is stable across re-renders and the checkout.
     *
     * @param  array<int, CartItem>  $items
     * @return array<int, true> cart item id => true
     */
    private function primaryItemIds(array $items): array
    {
        /** @var array<int, CartItem> $byWebinar  webinar id => chosen primary item */
        $byWebinar = [];

        foreach ($items as $item) {
            if (! $this->isWebinarOffering($item)) {
                continue;
            }

            /** @var Webinar $webinar */
            $webinar = $item->offering->offerable;
            $webinarId = (int) $webinar->getKey();
            $current = $byWebinar[$webinarId] ?? null;

            if ($current === null || $this->outranks($item, $current)) {
                $byWebinar[$webinarId] = $item;
            }
        }

        $primary = [];

        foreach ($byWebinar as $item) {
            $primary[$item->getKey()] = true;
        }

        return $primary;
    }

    private function outranks(CartItem $candidate, CartItem $current): bool
    {
        $candidatePrice = (int) $candidate->offering->price_cents;
        $currentPrice = (int) $current->offering->price_cents;

        if ($candidatePrice !== $currentPrice) {
            return $candidatePrice > $currentPrice;
        }

        // Same price: the lower offering id wins, for a stable choice.
        return (int) $candidate->offering->getKey() < (int) $current->offering->getKey();
    }

    private function isWebinarOffering(CartItem $item): bool
    {
        $offering = $item->offering;

        return $offering !== null
            && $offering->offerable instanceof Webinar;
    }
}

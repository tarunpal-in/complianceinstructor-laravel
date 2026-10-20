<?php

declare(strict_types=1);

namespace App\Cart;

use App\Models\Cart;
use App\Models\Offering;
use App\Models\Webinar;

/**
 * The "You might also like" add-on offers for a cart — §5.3 cross-sell.
 *
 * For each webinar already in the cart, its *other* single-format offerings that
 * carry an add-on price and are not in the cart yet. These are shown in the
 * mini-cart drawer at their add-on price, so adding one is the order bump.
 */
class CrossSell
{
    /**
     * @return list<array{offering: Offering, webinar: Webinar, format_label: string, price_cents: int}>
     */
    public function forCart(Cart $cart): array
    {
        $cart->loadMissing('items.offering.formats', 'items.offering.offerable');

        $webinarIds = [];
        $offeringIds = [];

        foreach ($cart->items as $item) {
            $offering = $item->offering;

            if ($offering === null) {
                continue;
            }

            $offeringIds[(int) $offering->getKey()] = true;

            if ($offering->offerable instanceof Webinar) {
                $webinarIds[(int) $offering->offerable->getKey()] = true;
            }
        }

        if ($webinarIds === []) {
            return [];
        }

        $candidates = Offering::query()
            ->where('offerable_type', Webinar::class)
            ->whereIn('offerable_id', array_keys($webinarIds))
            ->whereNotIn('id', array_keys($offeringIds))
            ->where('is_active', true)
            ->whereNotNull('addon_price_cents')
            ->with(['formats', 'offerable.speaker.media'])
            ->orderBy('sort_order')
            ->get();

        $suggestions = [];

        foreach ($candidates as $offering) {
            $webinar = $offering->offerable;
            $format = $offering->formatEnums()->first();

            // Single-format webinar offerings only — a bundle is not an add-on.
            if (! $webinar instanceof Webinar || $format === null || $offering->formatEnums()->count() !== 1) {
                continue;
            }

            $suggestions[] = [
                'offering' => $offering,
                'webinar' => $webinar,
                'format_label' => $format->label(),
                'price_cents' => $offering->priceCents(true),
            ];
        }

        return $suggestions;
    }
}

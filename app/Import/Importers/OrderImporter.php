<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Import\Maps\AddonFormats;
use App\Import\Maps\LegacyOrderStatus;
use App\Import\Support\LegacyMoney;
use App\Models\ImportMap;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;

/**
 * Orders, their line items, the formats each granted, and the named attendees —
 * Phase 3.
 *
 * One `shop_order` becomes one order header carrying its own WooCommerce number
 * (§5.4). A guest checkout (`_customer_user` 0) keeps `user_id` null and lives on
 * in the frozen billing snapshot. Each line resolves its product to the webinar
 * and its variation to the offering through the import map, and the `pa_addons`
 * term fans the line out into the formats it granted — the snapshot Phase 4
 * reads to build the entitlement ledger. Line items are written once (on first
 * import of an order), so a re-run never orphans the grants hung off them.
 */
class OrderImporter extends Importer
{
    public function label(): string
    {
        return 'Orders';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $orders = Legacy::table('posts')
            ->where('post_type', 'shop_order')
            ->whereIn('post_status', ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-refunded', 'wc-cancelled', 'wc-failed'])
            ->get();

        foreach ($orders as $post) {
            $id = (int) $post->ID;
            $meta = Legacy::postMetaMap($id);

            $customerWpId = (int) ($meta['_customer_user'] ?? 0);
            $userId = $customerWpId > 0 ? ImportMap::localId('user', $customerWpId) : null;

            $total = LegacyMoney::toCents($meta['_order_total'] ?? '0');
            $discount = LegacyMoney::toCents($meta['_cart_discount'] ?? '0');
            $tax = LegacyMoney::toCents($meta['_order_tax'] ?? '0');

            $order = $this->sync($result, 'order', $id, Order::class, function (Order $order) use ($post, $meta, $id, $userId, $total, $discount, $tax): void {
                $order->number = $this->orderNumber($meta, $id);
                $order->user_id = $userId;
                $order->status = LegacyOrderStatus::toEnum((string) $post->post_status);
                $order->currency = (string) ($meta['_order_currency'] ?? 'USD') ?: 'USD';
                $order->total_cents = $total;
                $order->discount_cents = $discount;
                $order->tax_cents = $tax;
                $order->subtotal_cents = $total + $discount - $tax;
                $order->billing_snapshot = $this->billingSnapshot($meta);
                $order->placed_at = $post->post_date ? Carbon::parse((string) $post->post_date) : null;
            });

            // Lines (and the grants hung off them) are written once per order.
            if ($order->items()->exists()) {
                continue;
            }

            $this->importLines($order, $id, $meta);
        }

        return $result;
    }

    /** @param  array<string, string>  $meta */
    private function importLines(Order $order, int $legacyOrderId, array $meta): void
    {
        $lines = Legacy::table('woocommerce_order_items')
            ->where('order_id', $legacyOrderId)
            ->where('order_item_type', 'line_item')
            ->get();

        $first = true;

        foreach ($lines as $line) {
            $itemMeta = $this->itemMeta((int) $line->order_item_id);

            $productId = (int) ($itemMeta['_product_id'] ?? 0);
            $variationId = (int) ($itemMeta['_variation_id'] ?? 0);
            $quantity = max(1, (int) ($itemMeta['_qty'] ?? 1));
            $lineTotal = LegacyMoney::toCents($itemMeta['_line_total'] ?? ($itemMeta['_line_subtotal'] ?? '0'));

            $item = new OrderItem;
            $item->order_id = $order->getKey();
            $item->webinar_id = $productId > 0 ? ImportMap::localId('webinar', $productId) : null;
            $item->offering_id = $variationId > 0 ? ImportMap::localId('offering', $variationId) : null;
            $item->description = (string) $line->order_item_name;
            $item->sku = $variationId > 0 ? 'WC-'.$variationId : null;
            $item->quantity = $quantity;
            $item->line_total_cents = $lineTotal;
            $item->unit_price_cents = intdiv($lineTotal, $quantity);
            $item->seats_per_unit = 1;
            $item->save();

            foreach ($this->lineFormats($itemMeta, $variationId) as $format) {
                $item->formats()->create(['format' => $format]);
            }

            // Named attendees are order-level in WooCommerce (billing1_*, billing2_*);
            // hang them on the first line, which is where a single-item order's
            // certificate seat belongs.
            if ($first) {
                foreach ($this->attendees($meta) as $attendee) {
                    $item->attendees()->create($attendee);
                }

                $first = false;
            }
        }
    }

    /**
     * The formats a line granted: its own `pa_addons` term, or the offering's
     * formats when the term is missing or unrecognised.
     *
     * @param  array<string, string>  $itemMeta
     * @return array<int, \App\Enums\Format>
     */
    private function lineFormats(array $itemMeta, int $variationId): array
    {
        $addon = (string) ($itemMeta['pa_addons'] ?? '');

        if ($addon !== '' && AddonFormats::knows($addon)) {
            return AddonFormats::forSlug($addon);
        }

        if ($variationId > 0 && ($offeringId = ImportMap::localId('offering', $variationId)) !== null) {
            return \App\Models\OfferingFormat::query()
                ->where('offering_id', $offeringId)
                ->pluck('format')
                ->all();
        }

        return [];
    }

    /**
     * Named attendees from the order's registrant fields.
     *
     * Real orders are messy: a slot without its own email falls back to the
     * billing email, and some orders stuff a whole team roster ("Name - email,
     * Name - email …") into one field, which is split back out. Everything is
     * length-capped to the column, deduped by email, and a slot with no email
     * anywhere is dropped rather than written blank.
     *
     * @param  array<string, string>  $meta
     * @return array<int, array{name: string, email: string}>
     */
    private function attendees(array $meta): array
    {
        $fallback = $this->firstEmail((string) ($meta['_billing_email'] ?? ''));
        $collected = [];

        foreach (['billing1', 'billing2', 'billing3'] as $prefix) {
            $first = trim((string) ($meta[$prefix.'_first_name'] ?? ''));
            $last = trim((string) ($meta[$prefix.'_last_name'] ?? ''));
            $ownEmail = trim((string) ($meta[$prefix.'_email'] ?? ''));

            if ($first === '' && $last === '' && $ownEmail === '') {
                continue;
            }

            // A roster pasted into one field — more than one address present.
            if (substr_count($first.' '.$ownEmail, '@') > 1) {
                foreach ($this->splitRoster($first.', '.$ownEmail, $fallback) as $attendee) {
                    $collected[] = $attendee;
                }

                continue;
            }

            $email = $this->firstEmail($ownEmail) ?: $fallback;

            if ($email === '') {
                continue;
            }

            $name = $this->cleanName(trim($first.' '.$last));
            $collected[] = ['name' => $name !== '' ? $name : $email, 'email' => $email];
        }

        if ($collected === [] && ($lone = $this->firstEmail((string) ($meta['attendee_email'] ?? ''))) !== '') {
            $collected[] = ['name' => $lone, 'email' => $lone];
        }

        return $this->dedupeByEmail($collected);
    }

    /** The first valid email in a string, lower-cased, or '' if there is none. */
    private function firstEmail(string $raw): string
    {
        $raw = trim($raw);

        if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL) !== false) {
            return strtolower($raw);
        }

        if (preg_match('/[^\s,;<>()"]+@[^\s,;<>()"]+\.[a-z]{2,}/i', $raw, $m) === 1) {
            return strtolower($m[0]);
        }

        return '';
    }

    private function cleanName(string $raw): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $raw)), 0, 255);
    }

    /**
     * Split a roster field into one attendee per "name - email" segment.
     *
     * @return array<int, array{name: string, email: string}>
     */
    private function splitRoster(string $raw, string $fallback): array
    {
        $out = [];

        foreach (preg_split('/[,\n;]+/', $raw) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $email = $this->firstEmail($segment) ?: $fallback;

            if ($email === '') {
                continue;
            }

            $name = $this->cleanName(trim(str_ireplace($email, '', $segment), " -\t"));
            $out[] = ['name' => $name !== '' ? $name : $email, 'email' => $email];
        }

        return $out;
    }

    /**
     * @param  array<int, array{name: string, email: string}>  $attendees
     * @return array<int, array{name: string, email: string}>
     */
    private function dedupeByEmail(array $attendees): array
    {
        $seen = [];
        $out = [];

        foreach ($attendees as $attendee) {
            if (isset($seen[$attendee['email']])) {
                continue;
            }

            $seen[$attendee['email']] = true;
            $out[] = $attendee;
        }

        return $out;
    }

    /** @param  array<string, string>  $meta */
    private function orderNumber(array $meta, int $legacyId): string
    {
        $number = trim((string) ($meta['_alg_wc_full_custom_order_number'] ?? ($meta['_alg_wc_custom_order_number'] ?? '')));

        return $number !== '' ? $number : (string) $legacyId;
    }

    /**
     * The frozen billing block — only the fields that have a value.
     *
     * @param  array<string, string>  $meta
     * @return array<string, string>
     */
    private function billingSnapshot(array $meta): array
    {
        $name = trim(((string) ($meta['_billing_first_name'] ?? '')).' '.((string) ($meta['_billing_last_name'] ?? '')));

        $snapshot = [
            'name' => $name,
            'email' => (string) ($meta['_billing_email'] ?? ''),
            'job_title' => (string) ($meta['_billing_job_title'] ?? ''),
            'company' => (string) ($meta['_billing_company'] ?? ''),
            'address_line1' => (string) ($meta['_billing_address_1'] ?? ''),
            'address_line2' => (string) ($meta['_billing_address_2'] ?? ''),
            'city' => (string) ($meta['_billing_city'] ?? ''),
            'region' => (string) ($meta['_billing_state'] ?? ''),
            'postal_code' => (string) ($meta['_billing_postcode'] ?? ''),
            'country' => (string) ($meta['_billing_country'] ?? ''),
            'phone' => (string) ($meta['_billing_phone'] ?? ''),
        ];

        return array_filter($snapshot, static fn (string $value): bool => trim($value) !== '');
    }

    /** @return array<string, string> */
    private function itemMeta(int $orderItemId): array
    {
        return Legacy::table('woocommerce_order_itemmeta')
            ->where('order_item_id', $orderItemId)
            ->pluck('meta_value', 'meta_key')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();
    }
}

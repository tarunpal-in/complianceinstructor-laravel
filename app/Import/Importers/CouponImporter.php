<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Import\Support\LegacyMoney;
use App\Models\Coupon;
use App\Models\ImportMap;
use Illuminate\Support\Carbon;

/**
 * Coupons, from the WooCommerce `shop_coupon` posts — Phase 3.
 *
 * The code is the post title; percent vs fixed comes from `discount_type`
 * (anything containing "percent" is a percentage, the rest is a fixed amount off
 * the cart). Usage caps of 0 mean "no cap" in WooCommerce, so they become null.
 * Category/product scoping is not carried — those coupons import as global and
 * are a small set for the client to re-scope if needed; the validator is
 * server-side either way (§8 #2).
 */
class CouponImporter extends Importer
{
    public function label(): string
    {
        return 'Coupons';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $coupons = Legacy::table('posts')
            ->where('post_type', 'shop_coupon')
            ->whereIn('post_status', ['publish', 'draft', 'pending'])
            ->get();

        foreach ($coupons as $coupon) {
            $id = (int) $coupon->ID;
            $code = trim((string) $coupon->post_title);

            if ($code === '') {
                $result->failed("Coupon {$id}: no code.");

                continue;
            }

            // Codes are unique, and the same code can appear twice in WordPress
            // (or already exist from a seed). Treat a duplicate as the same
            // coupon: map this legacy id onto the existing one rather than
            // colliding on the unique index.
            $upper = strtoupper($code);
            $existing = Coupon::query()->where('code', $upper)->first();

            if ($existing !== null && ImportMap::localId('coupon', $id) !== $existing->getKey()) {
                ImportMap::remember('coupon', $id, $existing);
                $result->skipped();

                continue;
            }

            $meta = Legacy::postMetaMap($id);
            $isPercent = str_contains(strtolower((string) ($meta['discount_type'] ?? 'fixed_cart')), 'percent');
            $amount = (string) ($meta['coupon_amount'] ?? '0');

            $this->sync($result, 'coupon', $id, Coupon::class, function (Coupon $model) use ($coupon, $code, $meta, $isPercent, $amount): void {
                $model->code = $code;
                $model->description = ($excerpt = trim(strip_tags((string) ($coupon->post_excerpt ?? '')))) !== '' ? $excerpt : null;
                $model->percent_off = $isPercent ? (int) $amount : null;
                $model->amount_off_cents = $isPercent ? null : LegacyMoney::toCents($amount);
                $model->currency = 'USD';
                $model->max_redemptions = $this->cap($meta['usage_limit'] ?? null);
                $model->max_per_user = $this->cap($meta['usage_limit_per_user'] ?? null);
                $model->minimum_spend_cents = $this->minimumSpend($meta['minimum_amount'] ?? null);
                $model->ends_at = $this->expiry($meta['date_expires'] ?? null);
                $model->is_active = $coupon->post_status === 'publish';
            });
        }

        return $result;
    }

    /** A WooCommerce usage limit of 0 (or absent) means no cap. */
    private function cap(mixed $value): ?int
    {
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }

    private function minimumSpend(mixed $value): ?int
    {
        $raw = (string) $value;

        return $raw !== '' && (float) $raw > 0 ? LegacyMoney::toCents($raw) : null;
    }

    private function expiry(mixed $value): ?Carbon
    {
        $ts = (int) $value;

        return $ts > 0 ? Carbon::createFromTimestamp($ts) : null;
    }
}

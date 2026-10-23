<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Import\Support\LegacyMoney;
use App\Models\CouponRedemption;
use App\Models\ImportMap;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Coupon redemptions, from `wc_order_coupon_lookup` — Phase 3.
 *
 * One row per (order, coupon) — the same uniqueness §5.4 puts on the table — so
 * the redemption count the admin reads is the real history. Resolves the coupon
 * and order through the import map and takes the user from the order (null for a
 * guest). Runs after CouponImporter and OrderImporter.
 */
class CouponRedemptionImporter extends Importer
{
    public function label(): string
    {
        return 'Coupon uses';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $rows = Legacy::table('wc_order_coupon_lookup')->get();

        foreach ($rows as $row) {
            $couponId = ImportMap::localId('coupon', (int) $row->coupon_id);
            $orderId = ImportMap::localId('order', (int) $row->order_id);

            if ($couponId === null || $orderId === null) {
                $result->skipped();

                continue;
            }

            $userId = Order::query()->whereKey($orderId)->value('user_id');
            $key = (int) sprintf('%u', crc32($row->order_id.'-'.$row->coupon_id));

            $this->sync($result, 'coupon_redemption', $key, CouponRedemption::class, function (CouponRedemption $redemption) use ($row, $couponId, $orderId, $userId): void {
                $redemption->coupon_id = $couponId;
                $redemption->order_id = $orderId;
                $redemption->user_id = $userId;
                $redemption->amount_cents = LegacyMoney::toCents((string) $row->discount_amount);
                $redemption->redeemed_at = $row->date_created ? Carbon::parse((string) $row->date_created) : now();
            });
        }

        return $result;
    }
}

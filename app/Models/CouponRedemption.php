<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $redeemed_at
 *
 * One use of a coupon — CLAUDE.md §5.4.
 *
 * Written inside the order transaction (§8 improvement #2), which is what makes
 * `max_redemptions` hold under concurrent checkouts rather than being a number
 * two people can both read before either writes.
 */
class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    protected $fillable = ['coupon_id', 'user_id', 'order_id', 'amount_cents', 'redeemed_at'];

    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

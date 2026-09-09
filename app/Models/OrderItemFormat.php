<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Format $format
 *
 * One format an order line granted.
 *
 * Mirrors `offering_format`, and exists for the same reason: an offering sells
 * bundles, and §5.2 keys entitlements per format, so a three-format line has to
 * fan out into three grants and be revoked as three.
 */
class OrderItemFormat extends Model
{
    public $timestamps = false;

    protected $table = 'order_item_format';

    protected $fillable = ['order_item_id', 'format'];

    protected function casts(): array
    {
        return ['format' => Format::class];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}

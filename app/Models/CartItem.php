<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in a cart — one offering at a quantity (CLAUDE.md §5.4).
 *
 * @property-read Offering|null $offering
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'offering_id', 'quantity', 'unit_price_cents'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /**
     * This line's subtotal at the offering's CURRENT price — never the stored
     * snapshot (§14 #1). An unavailable offering contributes nothing.
     */
    public function lineSubtotalCents(): int
    {
        return $this->isAvailable() ? $this->offering->priceCents() * $this->quantity : 0;
    }

    /** Whether the offering is still on sale (not removed or switched off). */
    public function isAvailable(): bool
    {
        return $this->offering !== null && $this->offering->is_active;
    }
}

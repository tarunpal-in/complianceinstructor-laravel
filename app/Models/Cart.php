<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A database cart — CLAUDE.md §5.4.
 *
 * Belongs to a user, or to a guest session by token. Totals are always
 * recomputed from the live offering price, never read back off the stored
 * snapshot (§14 #1), so a price change between adding and checking out is
 * honoured rather than locked in.
 *
 * @property \Illuminate\Database\Eloquent\Collection<int, CartItem> $items
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'session_token', 'currency', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** Live subtotal: the sum of each line at the offering's current price. */
    public function subtotalCents(): int
    {
        return $this->items->sum(fn (CartItem $item): int => $item->lineSubtotalCents());
    }

    /** Total units across every line, for the cart badge. */
    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }
}

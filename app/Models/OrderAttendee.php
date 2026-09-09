<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderAttendeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named seat on an order line — CLAUDE.md §15.
 *
 * The attendee is frequently not the purchaser: this audience buys seats for a
 * team. The certificate is issued against this name, so it is captured at
 * checkout rather than inferred from whoever paid.
 */
class OrderAttendee extends Model
{
    /** @use HasFactory<OrderAttendeeFactory> */
    use HasFactory;

    protected $fillable = ['order_item_id', 'name', 'email', 'user_id'];

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether this seat has been claimed by an account yet. */
    public function isClaimed(): bool
    {
        return $this->user_id !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property Webinar|null $webinar Null on a package line, which belongs to no single webinar.
 * @property Offering|null $offering Null once the offering it was sold from has been deleted.
 *
 * One line of an order — CLAUDE.md §5.4.
 *
 * The prices here are a snapshot. §5.3 makes `offerings` the only place a price
 * is *read* from when deciding what to charge; once charged, the amount has to
 * survive the offering being repriced, so it is copied rather than joined.
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id', 'offering_id', 'webinar_id', 'description', 'sku',
        'quantity', 'unit_price_cents', 'line_total_cents', 'seats_per_unit',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    /** @return HasMany<OrderItemFormat, $this> */
    public function formats(): HasMany
    {
        return $this->hasMany(OrderItemFormat::class);
    }

    /** @return HasMany<OrderAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(OrderAttendee::class);
    }

    /**
     * The formats this line granted, snapshotted at the time of sale.
     *
     * Read from the line rather than from the offering on purpose: an offering
     * can be edited afterwards, and a refund must revoke exactly what was
     * given, not what the offering happens to grant today.
     *
     * @return Collection<int, Format>
     */
    public function formatEnums(): Collection
    {
        return $this->formats->pluck('format');
    }

    /** How many named seats this line is entitled to. */
    public function seatCount(): int
    {
        return $this->quantity * $this->seats_per_unit;
    }

    /**
     * §14 #7 — the predecessor stored a unit price in a column called
     * `total_price`, so every revenue sum was out by a factor of quantity.
     * This asserts the two columns agree.
     */
    public function totalIsConsistent(): bool
    {
        return $this->line_total_cents === $this->unit_price_cents * $this->quantity;
    }
}

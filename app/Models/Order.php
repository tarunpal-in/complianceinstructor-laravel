<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Orders\GenerateOrderNumber;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property OrderStatus $status
 * @property int|null $user_id Null for a guest checkout (§7 P3).
 * @property User|null $user
 * @property Carbon|null $placed_at
 * @property array<string, mixed>|null $billing_snapshot
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 *
 * CLAUDE.md §5.4 — the order header.
 *
 * Its status is *derived* from payments, never assigned from input. §14 #2: the
 * predecessor completed any cart for free by posting `status=place_order`.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'user_id', 'status', 'subtotal_cents', 'discount_cents',
        'tax_cents', 'total_cents', 'currency', 'billing_snapshot', 'notes', 'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'billing_snapshot' => 'array',
            'placed_at' => 'datetime',
        ];
    }

    // Relationships ---------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasManyThrough<Refund, Payment, $this> */
    public function refunds(): HasManyThrough
    {
        return $this->hasManyThrough(Refund::class, Payment::class);
    }

    /** @return HasManyThrough<OrderAttendee, OrderItem, $this> */
    public function attendees(): HasManyThrough
    {
        return $this->hasManyThrough(OrderAttendee::class, OrderItem::class);
    }

    /**
     * Coupons used on this order.
     *
     * §5.4 puts the link on `coupon_redemptions` rather than a `coupon_id`
     * column, because one order can carry more than one code and because the
     * redemption row is what the cap is counted from. The relation exists so
     * reports and exports can read that without knowing the pivot.
     *
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    // Scopes ----------------------------------------------------------------

    /** @param  Builder<Order>  $query */
    public function scopeSettled(Builder $query): void
    {
        $query->whereIn('status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value]);
    }

    // Money -----------------------------------------------------------------

    /**
     * What has actually been captured, in cents.
     *
     * Only succeeded payments count. A pending one is an intent, and a failed
     * one is nothing at all.
     */
    public function capturedCents(): int
    {
        return (int) $this->payments
            ->where('status', PaymentStatus::Succeeded)
            ->sum('amount_cents');
    }

    /** What has been given back, in cents. */
    public function refundedCents(): int
    {
        return (int) $this->payments
            ->flatMap(fn (Payment $payment) => $payment->refunds)
            ->where('status', \App\Enums\RefundStatus::Succeeded)
            ->sum('amount_cents');
    }

    /**
     * Refunds agreed against this order that have not resolved yet.
     *
     * Across every payment on the order, not just one — two administrators
     * refunding different payments of the same order within the window is
     * exactly the case nobody sees from a single payment's screen.
     *
     * @return \Illuminate\Support\Collection<int, Refund>
     */
    public function refundsInFlight(): \Illuminate\Support\Collection
    {
        return $this->payments
            ->flatMap(fn (Payment $payment) => $payment->refunds)
            ->filter(fn (Refund $refund): bool => $refund->status->isInFlight())
            ->values();
    }

    /** Agreed and waiting, in cents. Not yet given back. */
    public function refundsInFlightCents(): int
    {
        return (int) $this->refundsInFlight()->sum('amount_cents');
    }

    public function outstandingCents(): int
    {
        return max(0, $this->total_cents - $this->capturedCents());
    }

    /**
     * The status the payment rows say this order is in.
     *
     * §9 — "Order status is derived from a `payments` row, never from request
     * input". Kept as a pure function so the admin can show the derived value
     * beside the stored one and make a mismatch visible rather than silently
     * trusting the column.
     */
    public function derivedStatus(): OrderStatus
    {
        $captured = $this->capturedCents();
        $refunded = $this->refundedCents();

        return match (true) {
            $captured === 0 && $this->payments->contains(
                fn (Payment $payment): bool => $payment->status === PaymentStatus::Failed
            ) => OrderStatus::Failed,
            $captured === 0 => OrderStatus::Pending,
            $refunded >= $captured => OrderStatus::Refunded,
            $refunded > 0 => OrderStatus::PartiallyRefunded,
            default => OrderStatus::Paid,
        };
    }

    /**
     * Whether the stored status still agrees with the payments.
     *
     * A mismatch means a webhook was missed or someone edited the column — both
     * worth seeing on the order rather than discovering from a support ticket.
     */
    public function statusMatchesPayments(): bool
    {
        if (in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
            return true;
        }

        return $this->status === $this->derivedStatus();
    }

    /**
     * The account this was placed under, or null for a guest checkout.
     *
     * Keyed off the column rather than the relation: the nullability lives in
     * `user_id`, and reading it here makes the guest case explicit at every
     * call site instead of resting on how the relation happens to be inferred.
     */
    public function customer(): ?User
    {
        return $this->user_id === null ? null : $this->user;
    }

    /**
     * The customer's name, from the account or the frozen snapshot.
     *
     * `->` rather than `?->`: `??` already swallows a null read, so the
     * nullsafe would be decoration. `customer()` still returns null for a guest
     * and the snapshot carries the name in that case.
     */
    public function customerName(): string
    {
        return $this->customer()->name
            ?? (string) ($this->billing_snapshot['name'] ?? 'Guest');
    }

    public function customerEmail(): string
    {
        return $this->customer()->email
            ?? (string) ($this->billing_snapshot['email'] ?? '');
    }

    /**
     * Take the next order number.
     *
     * The format is the client's to set, so the work lives in
     * GenerateOrderNumber rather than here — and calling it *advances the
     * counter*, which is why it belongs in one place inside the order
     * transaction rather than anywhere a number is merely wanted for display.
     */
    public static function nextNumber(?Carbon $at = null): string
    {
        return app(GenerateOrderNumber::class)($at);
    }
}

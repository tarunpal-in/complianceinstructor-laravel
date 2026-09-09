<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property PaymentGateway $gateway
 * @property PaymentStatus $status
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Database\Eloquent\Collection<int, Refund> $refunds
 *
 * CLAUDE.md §5.4 — one attempt to take money.
 *
 * Both gateways write here, so reporting never branches (§8 improvement #1),
 * and the UNIQUE on `gateway_payment_id` is what makes fulfilment idempotent.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, LogsActivity;

    /**
     * §9 — the predecessor had no audit trail at all.
     *
     * A manual payment has no gateway confirmation behind it, so this log is
     * the only evidence of who decided an invoice had been settled.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['gateway', 'status', 'amount_cents', 'reference', 'processed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payment');
    }

    protected $fillable = [
        'order_id', 'gateway', 'gateway_payment_id', 'status', 'amount_cents',
        'currency', 'raw_response', 'recorded_by', 'reference', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'status' => PaymentStatus::class,
            'raw_response' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Already given back, in cents. */
    public function refundedCents(): int
    {
        return (int) $this->refunds
            ->where('status', RefundStatus::Succeeded)
            ->sum('amount_cents');
    }

    /**
     * The most that may still be refunded.
     *
     * Refunding more than was taken is the kind of arithmetic slip that only
     * shows up in a bank reconciliation weeks later, so the ceiling is computed
     * rather than trusted to whoever fills the form.
     */
    public function refundableCents(): int
    {
        if ($this->status !== PaymentStatus::Succeeded) {
            return 0;
        }

        return max(0, $this->amount_cents - $this->refundedCents());
    }
}

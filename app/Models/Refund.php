<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property RefundStatus $status
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $processed_at
 * @property Carbon|null $cancelled_at
 *
 * CLAUDE.md §5.4. Its own row against a payment, so the original amount stays
 * readable and partial refunds accumulate rather than overwrite.
 *
 * It is created `scheduled` and sits out a cooling-off window before reaching
 * the gateway — see the migration and RequestRefund.
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'payment_id', 'gateway_refund_id', 'status', 'scheduled_for', 'amount_cents',
        'reason', 'gateway_message', 'issued_by', 'processed_at', 'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'scheduled_for' => 'datetime',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * §9 — who gave money back, and when. The one entry a finance dispute
     * always comes down to. Cancellations are logged for the same reason: not
     * refunding is also a decision somebody made.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_cents', 'reason', 'scheduled_for', 'processed_at', 'cancelled_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('refund');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Scheduled refunds whose window has closed.
     *
     * @param  Builder<Refund>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', RefundStatus::Scheduled->value)
            ->where('scheduled_for', '<=', now());
    }

    /** Whether it can still be stopped. */
    public function isStoppable(): bool
    {
        return $this->status->isStoppable();
    }

    /** How long is left before it goes, or null once it has. */
    public function windowRemaining(): ?string
    {
        if (! $this->isStoppable() || $this->scheduled_for === null) {
            return null;
        }

        return $this->scheduled_for->isPast()
            ? 'any moment now'
            : $this->scheduled_for->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE);
    }
}

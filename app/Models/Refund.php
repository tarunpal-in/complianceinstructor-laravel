<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property RefundStatus $status
 * @property \Illuminate\Support\Carbon|null $processed_at
 *
 * CLAUDE.md §5.4. Its own row against a payment, so the original amount stays
 * readable and partial refunds accumulate rather than overwrite.
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, LogsActivity;

    /**
     * §9 — who gave money back, and when. The one entry a finance dispute
     * always comes down to.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_cents', 'reason', 'processed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('refund');
    }

    protected $fillable = [
        'payment_id', 'gateway_refund_id', 'status', 'amount_cents',
        'reason', 'issued_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'processed_at' => 'datetime',
        ];
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
}

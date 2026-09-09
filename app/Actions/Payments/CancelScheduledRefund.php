<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stops a refund during its cooling-off window.
 *
 * The window is worth nothing without this — a delay nobody can act on just
 * makes refunds slower. Cancelling releases the amount back to the payment, so
 * a corrected refund can be raised for the right figure.
 */
class CancelScheduledRefund
{
    public function __invoke(Refund $refund, ?int $cancelledBy = null): Refund
    {
        return DB::transaction(function () use ($refund, $cancelledBy): Refund {
            $fresh = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            // Taken under the lock: a sweep may have picked it up between the
            // page rendering the button and someone pressing it, and once the
            // gateway has it there is nothing left to cancel.
            if (! $fresh->isStoppable()) {
                throw new RuntimeException(
                    'This refund has already been sent, so it can no longer be stopped.'
                );
            }

            $fresh->update([
                'status' => RefundStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
            ]);

            return $fresh;
        });
    }
}

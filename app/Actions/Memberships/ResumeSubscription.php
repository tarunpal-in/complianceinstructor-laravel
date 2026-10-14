<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\Subscription;
use RuntimeException;

/**
 * Undoes a cancellation while the member is still inside their grace period —
 * CLAUDE.md §6 / P4.
 *
 * Only on grace: once the period has actually ended the subscription is gone at
 * Stripe and there is nothing to resume — the member subscribes afresh. Resuming
 * leaves the allowance snapshot and term untouched, because nothing about what
 * they hold changed; only the scheduled end was taken back.
 */
class ResumeSubscription
{
    public function __invoke(Subscription $subscription): Subscription
    {
        if (! $subscription->onGracePeriod()) {
            throw new RuntimeException('This membership is not scheduled to end, so there is nothing to resume.');
        }

        $subscription->resume();

        return $subscription->refresh();
    }
}

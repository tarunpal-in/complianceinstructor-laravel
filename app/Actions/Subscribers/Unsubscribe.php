<?php

declare(strict_types=1);

namespace App\Actions\Subscribers;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;

/**
 * Taking somebody off the list.
 *
 * Never a delete. The row is the record of the refusal, and it is the only
 * thing that stops the next import putting them straight back on — see the
 * migration. Idempotent, because an unsubscribe link lives in every email that
 * was ever sent and will be clicked twice.
 */
class Unsubscribe
{
    public function __invoke(Subscriber $subscriber, ?string $reason = null): Subscriber
    {
        if ($subscriber->status === SubscriberStatus::Unsubscribed) {
            return $subscriber;
        }

        $subscriber->forceFill([
            'status' => SubscriberStatus::Unsubscribed,
            'unsubscribed_at' => now(),
            'unsubscribe_reason' => $reason,
        ])->save();

        return $subscriber;
    }
}

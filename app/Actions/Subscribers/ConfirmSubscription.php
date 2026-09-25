<?php

declare(strict_types=1);

namespace App\Actions\Subscribers;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;

/**
 * The second half of a double opt-in: the mailbox proving it is theirs.
 *
 * Only moves a pending row. Somebody clicking an old confirmation link after
 * they have since unsubscribed must not be put back on the list by it — the
 * later decision is the one that stands.
 */
class ConfirmSubscription
{
    public function __invoke(Subscriber $subscriber): Subscriber
    {
        if ($subscriber->status !== SubscriberStatus::Pending) {
            return $subscriber;
        }

        $subscriber->forceFill([
            'status' => SubscriberStatus::Subscribed,
            'confirmed_at' => now(),
            // Only set if it was somehow missed, so the original act of signing
            // up keeps its own date rather than being restamped on confirm.
            'subscribed_at' => $subscriber->subscribed_at ?? now(),
        ])->save();

        return $subscriber;
    }
}

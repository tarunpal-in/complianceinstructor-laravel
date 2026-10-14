<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Memberships\ReconcileSubscription;
use App\Models\Subscription;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Keeps our subscription snapshot in step with Stripe's — CLAUDE.md §8 / P4.
 *
 * Cashier owns the webhook endpoint and, for subscription events, has already
 * updated `stripe_status`, `stripe_price` and `ends_at` on the row by the time
 * this runs (its listener is registered first). What Cashier does not know about
 * is our allowance snapshot and plan link, so on a change we hand the (now
 * current) subscription to ReconcileSubscription, which re-snapshots if the
 * billed plan has moved.
 *
 * Status and cancellation need nothing more here: `Subscription::state()` reads
 * the columns Cashier just wrote, at query time.
 */
class SyncSubscriptionFromWebhook
{
    private const HANDLED = [
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(private readonly ReconcileSubscription $reconcile) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (! in_array($payload['type'] ?? null, self::HANDLED, true)) {
            return;
        }

        $stripeId = $payload['data']['object']['id'] ?? null;

        if (! is_string($stripeId) || $stripeId === '') {
            return;
        }

        $subscription = Subscription::query()->where('stripe_id', $stripeId)->first();

        if ($subscription === null) {
            return;
        }

        ($this->reconcile)($subscription);
    }
}

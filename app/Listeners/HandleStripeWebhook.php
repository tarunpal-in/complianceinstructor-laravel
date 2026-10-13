<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Payments\ProcessStripeCaptureEvent;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Finalises order payments from Stripe's webhook — CLAUDE.md §14 #6.
 *
 * Reuses Cashier's webhook endpoint and signature verification (it already
 * listens at `stripe/webhook` with the webhook secret and fires WebhookReceived
 * for every verified event), rather than standing up a second Stripe endpoint
 * with its own verification. We act only on payment_intent.succeeded; everything
 * else (subscription events) Cashier has already handled.
 */
class HandleStripeWebhook
{
    public function __construct(private readonly ProcessStripeCaptureEvent $process) {}

    public function handle(WebhookReceived $event): void
    {
        ($this->process)($event->payload);
    }
}

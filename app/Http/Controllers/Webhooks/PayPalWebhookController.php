<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\ProcessPaypalCaptureEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal payment webhook — CLAUDE.md §9 / §14 #6.
 *
 * The source of truth for a payment, independent of the browser: a buyer who
 * pays and closes the tab is still finalised here. Authenticity is PayPal's own
 * signature, verified against the webhook id before anything is trusted — an
 * unverified or unverifiable event is refused. Finalising is idempotent
 * (ProcessPaypalCaptureEvent → RecordCapture), so an event replayed by PayPal,
 * or one that races the browser return, grants nothing twice.
 */
class PayPalWebhookController extends Controller
{
    public function __construct(private readonly ProcessPaypalCaptureEvent $process) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->verified($request)) {
            Log::warning('PayPal webhook rejected: signature not verified.');

            return response('', 403);
        }

        /** @var array<string, mixed> $event */
        $event = $request->json()->all();
        ($this->process)($event);

        // 200 regardless of whether this event type was acted on, so PayPal does
        // not retry an event we have legitimately ignored.
        return response('', 200);
    }

    private function verified(Request $request): bool
    {
        $webhookId = (string) config('services.paypal.webhook_id');

        // No webhook id means we cannot prove the event is PayPal's, so we do
        // not trust it — better a missed event than a forged one.
        if ($webhookId === '' || ($token = $this->accessToken()) === null) {
            return false;
        }

        $response = Http::withToken($token)->post($this->base().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $request->json()->all(),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    private function accessToken(): ?string
    {
        $id = (string) config('services.paypal.client_id');
        $secret = (string) config('services.paypal.client_secret');

        if ($id === '' || $secret === '') {
            return null;
        }

        $response = Http::asForm()->withBasicAuth($id, $secret)
            ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        return $response->successful() ? (string) $response->json('access_token') : null;
    }

    private function base(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}

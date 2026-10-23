<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payments\RecordCapture;
use App\Cart\CartManager;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Seo\Seo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Taking payment on a pending order — CLAUDE.md §3 (P3).
 *
 * The page renders the gateway's own UI (PayPal Buttons / Stripe Elements); its
 * two endpoints set up and then finalise the payment. Finalising goes through
 * RecordCapture — the same idempotent action the webhook uses — so a closed tab,
 * a double click or a replayed webhook cannot grant twice (§14 #5/#6). Nothing
 * here reads an amount from the request: the gateway is handed the order total
 * PlaceOrder computed (§14 #1).
 */
class PaymentController extends Controller
{
    public function show(Order $order): View|RedirectResponse
    {
        $this->authorizeOrder($order);

        $order->load('payments');

        // Already paid (the webhook got there first, or a refresh): straight to
        // the receipt rather than a second payment.
        if ($order->derivedStatus()->isSettled()) {
            return redirect()->route('checkout.complete', $order);
        }

        return view('checkout.pay', [
            'seo' => Seo::site('Payment', robots: 'noindex, nofollow'),
            'order' => $order,
            'gateway' => $this->chosenGateway(),
            // 'card' or 'paypal' for the PayPal flow — which PayPal button the
            // pay page renders. Empty for a direct visit or the Stripe backup.
            'funding' => (string) session('checkout_funding', ''),
        ]);
    }

    /** Set up the payment with the gateway and hand the front end what it needs. */
    public function start(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($order);

        $gateway = $this->gatewayFrom($request);

        // Switched off in admin (or never on): refused server-side, not only
        // hidden from the checkout form.
        if (! \App\Support\PaymentGateways::isEnabled($gateway)) {
            return response()->json(['error' => $gateway->label().' is not available right now.'], 422);
        }

        $driver = app(\App\Payments\PaymentGatewayManager::class)->driver($gateway);

        if (! $driver->isConfigured()) {
            return response()->json(['error' => $gateway->label().' is not available right now.'], 422);
        }

        $intent = $driver->createCheckout($order);

        return response()->json([
            'gatewayOrderId' => $intent->gatewayOrderId,
            'clientSecret' => $intent->clientSecret,
            'approveUrl' => $intent->approveUrl,
        ]);
    }

    /** Capture what the buyer approved, finalise idempotently, and clear the cart. */
    public function complete(Request $request, Order $order, RecordCapture $record): JsonResponse
    {
        $this->authorizeOrder($order);

        $reference = trim((string) $request->input('reference'));

        if ($reference === '') {
            return response()->json(['error' => 'Missing payment reference.'], 422);
        }

        $gateway = $this->gatewayFrom($request);

        // Same server-side gate as start(): never capture through a gateway that
        // is switched off. This is what stops a request selecting `manual` —
        // which has no enabled key and whose driver would otherwise report a
        // free capture as succeeded — to fake a paid order (§9 free-order
        // bypass / §14 #2: fulfilment must follow a real gateway payment).
        if (! \App\Support\PaymentGateways::isEnabled($gateway)) {
            return response()->json(['error' => 'That payment method is not available.'], 422);
        }

        $driver = app(\App\Payments\PaymentGatewayManager::class)->driver($gateway);

        $payment = $record($order, $gateway, $driver->capture($order, $reference));

        if ($payment->status !== PaymentStatus::Succeeded) {
            return response()->json(['error' => 'The payment did not complete. Please try again.'], 422);
        }

        // Paid: clear the cart now, in the browser flow where the session exists.
        if (($cart = app(CartManager::class)->peek()) !== null) {
            app(CartManager::class)->clear($cart);
        }

        return response()->json(['redirect' => route('checkout.complete', $order)]);
    }

    /** The order's owner, or whoever just placed it (its id is in the session). */
    private function authorizeOrder(Order $order): void
    {
        $user = auth()->user();
        $isOwner = $user !== null && $order->user_id === $user->getKey();
        $justPlaced = session('recent_order_id') === $order->getKey();

        if (! $isOwner && ! $justPlaced) {
            throw new NotFoundHttpException;
        }
    }

    private function gatewayFrom(Request $request): PaymentGateway
    {
        return PaymentGateway::tryFrom((string) $request->input('gateway', $this->chosenGateway()->value))
            ?? $this->chosenGateway();
    }

    private function chosenGateway(): PaymentGateway
    {
        $chosen = PaymentGateway::tryFrom((string) session('checkout_gateway'));

        // Honour the buyer's choice only while it is still switched on — an admin
        // may have disabled it between placing and paying.
        if ($chosen !== null && \App\Support\PaymentGateways::isEnabled($chosen)) {
            return $chosen;
        }

        return \App\Support\PaymentGateways::defaultGateway() ?? PaymentGateway::PayPal;
    }
}

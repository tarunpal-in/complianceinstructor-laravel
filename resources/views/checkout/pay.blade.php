@extends('layouts.public')

@php
    use App\Support\Money;
    $clientId = (string) config('services.paypal.client_id');
    $stripeKey = (string) config('services.stripe.key');
@endphp

@section('content')
    <section class="section pay-page">
        <div class="pay-card">
            <h1>Complete your payment</h1>
            <p class="pay-order">Order <strong>{{ $order->number }}</strong></p>

            <dl class="pay-totals">
                <div><dt>Subtotal</dt><dd>{{ Money::format($order->subtotal_cents, $order->currency) }}</dd></div>
                @if ($order->discount_cents > 0)
                    <div><dt>Discount</dt><dd>−{{ Money::format($order->discount_cents, $order->currency) }}</dd></div>
                @endif
                <div class="pay-total"><dt>Total due</dt><dd>{{ Money::format($order->total_cents, $order->currency) }}</dd></div>
            </dl>

            <p id="pay-error" class="pay-error" role="alert"></p>

            @if ($gateway === App\Enums\PaymentGateway::PayPal)
                @if ($clientId === '')
                    <p class="pay-note">PayPal is not configured yet. Add <code>PAYPAL_CLIENT_ID</code> and
                        <code>PAYPAL_CLIENT_SECRET</code> to enable payments.</p>
                @else
                    <div id="paypal-buttons" class="pay-buttons"></div>
                @endif
            @elseif ($gateway === App\Enums\PaymentGateway::Stripe)
                @if ($stripeKey === '')
                    <p class="pay-note">Card payments are not configured yet. Add <code>STRIPE_KEY</code> and
                        <code>STRIPE_SECRET</code> to enable them.</p>
                @else
                    <div id="stripe-payment-element" class="pay-stripe"></div>
                    <button type="button" id="stripe-pay" class="btn-primary co-place">Pay {{ Money::format($order->total_cents, $order->currency) }}</button>
                @endif
            @endif

            <p class="pay-trust"><x-ui.icon name="shield-check" :size="14" /> Secure payment · 7-day refund</p>
        </div>
    </section>

    @if ($gateway === App\Enums\PaymentGateway::PayPal && $clientId !== '')
        <script src="https://www.paypal.com/sdk/js?client-id={{ $clientId }}&currency={{ $order->currency }}&intent=capture&enable-funding=card"></script>
        <script>
            (function () {
                if (!window.paypal) {
                    document.getElementById('pay-error').textContent = 'Could not load PayPal. Please refresh.';
                    return;
                }

                const csrf = '{{ csrf_token() }}';
                const startUrl = '{{ route('checkout.pay.start', $order) }}';
                const completeUrl = '{{ route('checkout.pay.complete', $order) }}';
                const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf };
                const errorBox = document.getElementById('pay-error');

                // Both checkout options capture through PayPal — the server stored
                // which funding the buyer chose, so we render that one button: the
                // hosted card form for "Credit or Debit Card", the wallet for "PayPal".
                const funding = @json($funding);

                const handlers = {
                    createOrder: function () {
                        return fetch(startUrl, { method: 'POST', headers, body: JSON.stringify({ gateway: 'paypal' }) })
                            .then((r) => r.json())
                            .then((d) => { if (d.error) throw new Error(d.error); return d.gatewayOrderId; });
                    },
                    onApprove: function (data) {
                        return fetch(completeUrl, { method: 'POST', headers, body: JSON.stringify({ gateway: 'paypal', reference: data.orderID }) })
                            .then((r) => r.json())
                            .then((d) => {
                                if (d.error) { errorBox.textContent = d.error; return; }
                                window.location = d.redirect;
                            });
                    },
                    onError: function () {
                        errorBox.textContent = 'Something went wrong with PayPal. Please try again.';
                    },
                };

                let source = null;
                if (funding === 'card') source = paypal.FUNDING.CARD;
                else if (funding === 'paypal') source = paypal.FUNDING.PAYPAL;

                let buttons = paypal.Buttons(source ? Object.assign({ fundingSource: source }, handlers) : handlers);

                // If the chosen funding is not available in this context, fall
                // back to the full set of PayPal buttons rather than an empty box.
                if (source && !buttons.isEligible()) {
                    buttons = paypal.Buttons(handlers);
                }

                buttons.render('#paypal-buttons');
            })();
        </script>
    @endif

    @if ($gateway === App\Enums\PaymentGateway::Stripe && $stripeKey !== '')
        <script src="https://js.stripe.com/v3/"></script>
        <script>
            (function () {
                if (!window.Stripe) {
                    document.getElementById('pay-error').textContent = 'Could not load Stripe. Please refresh.';
                    return;
                }

                const stripe = Stripe('{{ $stripeKey }}');
                const csrf = '{{ csrf_token() }}';
                const startUrl = '{{ route('checkout.pay.start', $order) }}';
                const completeUrl = '{{ route('checkout.pay.complete', $order) }}';
                const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf };
                const errorBox = document.getElementById('pay-error');
                const payButton = document.getElementById('stripe-pay');

                // Ask the server to set up a PaymentIntent, then mount Elements on
                // its client secret. The amount is the server's (§14 #1).
                fetch(startUrl, { method: 'POST', headers, body: JSON.stringify({ gateway: 'stripe' }) })
                    .then((r) => r.json())
                    .then((d) => {
                        if (d.error || !d.clientSecret) { errorBox.textContent = d.error || 'Could not start payment.'; return; }

                        const elements = stripe.elements({ clientSecret: d.clientSecret });
                        elements.create('payment').mount('#stripe-payment-element');

                        payButton.addEventListener('click', async () => {
                            payButton.disabled = true;
                            errorBox.textContent = '';

                            const { error, paymentIntent } = await stripe.confirmPayment({ elements, redirect: 'if_required' });

                            if (error) { errorBox.textContent = error.message; payButton.disabled = false; return; }

                            if (paymentIntent && paymentIntent.status === 'succeeded') {
                                const res = await fetch(completeUrl, {
                                    method: 'POST', headers,
                                    body: JSON.stringify({ gateway: 'stripe', reference: paymentIntent.id }),
                                }).then((r) => r.json());

                                if (res.error) { errorBox.textContent = res.error; payButton.disabled = false; return; }
                                window.location = res.redirect;
                            } else {
                                errorBox.textContent = 'The payment did not complete.';
                                payButton.disabled = false;
                            }
                        });
                    });
            })();
        </script>
    @endif
@endsection

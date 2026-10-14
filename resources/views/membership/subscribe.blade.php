@extends('layouts.public')

@php
    use App\Support\Money;
    $stripeKey = (string) (config('services.stripe.key') ?? config('cashier.key'));
@endphp

@section('content')
    <section class="section mb-subscribe">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="{{ route('membership.index') }}">Memberships</a></li>
                <li aria-current="page">{{ $plan->name }}</li>
            </ol>
        </nav>

        <div class="mb-subscribe-grid">
            {{-- Order summary — the plan, its term and exactly what it grants. --}}
            <aside class="mb-summary">
                <h2>{{ $plan->name }}</h2>
                <div class="mb-summary-price">
                    {{ $plan->cardPrice() }} <small>/ {{ $plan->describeTerm() }}</small>
                </div>

                @if ($headline = $plan->describeHeadline())
                    <p class="mb-summary-headline">{{ $headline }}</p>
                @endif

                <ul class="mb-allowances">
                    @foreach ($plan->allowanceLines() as $line)
                        <li><x-ui.icon name="check" :size="16" /> {{ $line }}</li>
                    @endforeach
                </ul>

                <dl class="mb-summary-total">
                    <div><dt>Due today</dt><dd>{{ Money::format($plan->price_cents, $plan->currency) }}</dd></div>
                </dl>
            </aside>

            {{-- Payment — inline Stripe Elements (SetupIntent). The card is set up
                 here, 3DS and all; Cashier charges the first invoice server-side
                 against the confirmed method. No price ever leaves the browser. --}}
            <div class="mb-pay">
                <h1>Complete your membership</h1>
                <p class="mb-pay-lead">You are subscribing as <strong>{{ auth()->user()->email }}</strong>.</p>

                <p id="mb-error" class="mb-pay-error" role="alert"></p>

                @if ($stripeKey === '')
                    <div class="mb-pay-placeholder" role="note">
                        <p>Card payments are not configured yet. Add <code>STRIPE_KEY</code> and
                            <code>STRIPE_SECRET</code> to enable them.</p>
                    </div>
                @else
                    <div id="mb-payment-element"></div>
                    <button type="button" id="mb-subscribe" class="btn-primary mb-subscribe-btn">
                        Subscribe for {{ Money::format($plan->price_cents, $plan->currency) }}
                    </button>
                @endif

                <p class="mb-pay-trust"><x-ui.icon name="shield-check" :size="14" /> Secure payment · cancel anytime · access to term end</p>
            </div>
        </div>
    </section>

    @if ($stripeKey !== '')
        <script src="https://js.stripe.com/v3/"></script>
        <script>
            (function () {
                if (!window.Stripe) {
                    document.getElementById('mb-error').textContent = 'Could not load Stripe. Please refresh.';
                    return;
                }

                const stripe = Stripe('{{ $stripeKey }}');
                const headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                };
                const intentUrl = '{{ route('membership.subscribe.intent', $plan) }}';
                const confirmUrl = '{{ route('membership.subscribe.confirm', $plan) }}';
                const errorBox = document.getElementById('mb-error');
                const button = document.getElementById('mb-subscribe');

                // Set up the card first; Cashier charges the first invoice against
                // it once the subscription is created.
                fetch(intentUrl, { method: 'POST', headers, body: '{}' })
                    .then((r) => r.json())
                    .then((d) => {
                        if (d.error || !d.clientSecret) { errorBox.textContent = d.error || 'Could not start payment.'; return; }

                        const elements = stripe.elements({ clientSecret: d.clientSecret });
                        elements.create('payment').mount('#mb-payment-element');

                        button.addEventListener('click', async () => {
                            button.disabled = true;
                            errorBox.textContent = '';

                            const { error, setupIntent } = await stripe.confirmSetup({ elements, redirect: 'if_required' });

                            if (error) { errorBox.textContent = error.message; button.disabled = false; return; }

                            const res = await fetch(confirmUrl, {
                                method: 'POST', headers,
                                body: JSON.stringify({ payment_method: setupIntent.payment_method }),
                            }).then((r) => r.json());

                            if (res.redirect) { window.location = res.redirect; return; }

                            // A first invoice needing extra authentication, or a
                            // refusal we can explain.
                            errorBox.textContent = res.error || 'We could not complete your subscription. Please try again.';
                            button.disabled = false;
                        });
                    });
            })();
        </script>
    @endif
@endsection

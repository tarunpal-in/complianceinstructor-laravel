@props([
    'plans' => collect(),
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
    'callbackHeading' => null,
    'callbackBody' => null,
    'callbackButton' => null,
    'callbackUrl' => null,
])

@php
    // The list is always the live `membership_plans` (§5.5) — the badge comes
    // from `plan_entitlements`, so the number a customer reads is the number
    // they are granted. Only the wrapper copy is editable; defaults match the
    // comp so a bare <x-home.pricing :plans> is unchanged.
    $eyebrow = $eyebrow ?: 'FLEXIBLE PRICING';
    $heading = $heading ?: 'Membership Plans';
    $intro = $intro ?: 'Invest in your professional development with a plan that matches your learning pace.';
    $callbackHeading = $callbackHeading ?: 'Not sure which plan is right for you?';
    $callbackBody = $callbackBody ?: 'Our team can help match you with the best training package for your goals and budget.';
    $callbackButton = $callbackButton ?: 'Request a Callback';
@endphp

<section class="pricing section" id="memberships">
    <div class="section-heading centered">
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h2>{{ $heading }}</h2>
        <p>{{ $intro }}</p>
    </div>

    @if ($plans->isNotEmpty())
        <div class="plans-grid" style="--plan-columns: {{ min($plans->count(), 4) }}">
            @foreach ($plans as $plan)
                <article @class(['plan', 'popular' => $plan->is_featured])>
                    @if ($plan->is_featured)
                        <span class="popular-tag">MOST POPULAR</span>
                    @endif

                    {{-- Sentence case, not uppercase: the comp renders the plan name
                         as-is and leans on `.plan h3 { letter-spacing: .1em }`. --}}
                    <h3>{{ $plan->name }}</h3>

                    <div class="price">{{ $plan->cardPrice() }} <small>/ {{ $plan->describeTerm() }}</small></div>

                    @if ($headline = $plan->describeHeadline())
                        <b class="live-count">{{ $headline }}</b>
                    @endif

                    <hr>

                    @foreach ($plan->highlightRows() as $highlight)
                        <p @class(['feature', 'muted' => ! $highlight['included']])>
                            @if ($highlight['included'])
                                <x-ui.icon name="check" :size="16" />
                            @else
                                <span aria-hidden="true">⊗</span>
                            @endif
                            {{ $highlight['label'] }}
                        </p>
                    @endforeach

                    {{-- A real link for a sellable plan (active + a Stripe price);
                         otherwise the enquiry prompt, since there is nothing to
                         subscribe to yet. --}}
                    @if ($plan->isSellable())
                        <a class="plan-cta" href="{{ route('membership.subscribe', $plan) }}">Get Started</a>
                    @else
                        <button @click="showNotice('{{ $plan->name }} — contact us to subscribe')">Contact Us</button>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <div class="callback">
        <div>
            <strong>{{ $callbackHeading }}</strong>
            <span>{{ $callbackBody }}</span>
        </div>
        @if (filled($callbackUrl))
            <a class="callback-btn" href="{{ $callbackUrl }}">{{ $callbackButton }}</a>
        @else
            <button @click="showNotice('We will be in touch soon')">{{ $callbackButton }}</button>
        @endif
    </div>
</section>
